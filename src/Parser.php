<?php

declare(strict_types=1);

namespace Revund\PHPWorker;

use PhpParser\Comment;
use PhpParser\Error as PhpParserError;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Revund\Worker\V1\BlockRef;
use Revund\Worker\V1\ConcernEvidenceRef;
use Revund\Worker\V1\DeclRef;
use Revund\Worker\V1\FunctionRef;
use Revund\Worker\V1\ImportRef;
use Revund\Worker\V1\ParsedFile;

/**
 * Parser is the domain object that walks each requested PHP file via
 * nikic/PHP-Parser and produces the ParsedFile messages the Go-side
 * structural detectors consume.
 *
 * Mirrors `workers/ts/src/parser.ts` structurally — the same six
 * collectors, the same hashing scheme, the same block-extraction
 * shape. Cross-language symmetry isn't accidental; it's how the
 * canonical-hash detector can cluster PHP and TS functions that
 * share a shape.
 *
 * # Hashing scheme
 *
 * Two hashes per function:
 *
 *   - Hash (language-specific): captures PHP-flavored token kinds
 *     including the operator on BinaryOp nodes. Two PHP functions
 *     with the same Hash share AST shape modulo identifier names
 *     and literal values.
 *   - CanonicalHash (cross-language): same idea but using the
 *     shared canonical token vocabulary defined in
 *     core/pkg/structural/lang/canonical.go. Lets the cross-language
 *     duplicate detector cluster equivalent functions across PHP,
 *     TS, and Go.
 *
 * Both hashes use SHA-1 truncated to 16 hex chars — same as the Go
 * and TS sides. Trivial bodies (≤2 nodes) short-circuit to "".
 *
 * # Concerns
 *
 * Per-file concerns are classified into ConcernEvidenceRef entries
 * tagged with one of the eight canonical categories
 * (presentation / state / network / dataaccess / io / config /
 * business / transport). The classifier looks at:
 *
 *   - Import paths (use statements pulling in known network /
 *     dataaccess / IO namespaces)
 *   - Call sites (static and instance) matching known method names
 *   - Property access patterns (`$_ENV`, etc.) for config
 *   - Function complexity ≥ 8 → business
 *
 * The taxonomy lives in PARSER concerns_*.md TODO once we have one;
 * for now, the in-code tables are the source of truth.
 *
 * # Error tolerance
 *
 * Syntactically broken PHP still yields a partial ParsedFile with
 * whatever the parser salvaged (imports + decls usually survive; the
 * specific function that broke parsing might be absent). parse_error
 * carries the parser's error message verbatim.
 */
final class Parser
{
    private \PhpParser\Parser $phpParser;

    /** @var int Cyclomatic complexity above which a function is tagged as Business. */
    private const BUSINESS_COMPLEXITY = 8;

    /** @var int Minimum statements a block must contain to qualify for hashing. */
    private const MIN_BLOCK_STMTS = 3;

    /** @var int Bytes of body-hash output (16 hex chars). */
    private const HASH_BYTES = 8;

    public function __construct()
    {
        // createForHostVersion() picks the PHP version the worker
        // itself runs on. That's a sane default — bot deploys ship a
        // pinned PHP image, and customer projects on older PHP versions
        // still parse fine (nikic/PHP-Parser is backward-tolerant).
        $this->phpParser = (new ParserFactory())->createForHostVersion();
    }

    /**
     * @param string   $repoPath Absolute repo root path.
     * @param string[] $relPaths Repo-relative file paths.
     *
     * @return ParsedFile[]
     */
    public function parseFiles(string $repoPath, array $relPaths): array
    {
        $out = [];
        foreach ($relPaths as $rel) {
            $out[] = $this->parseOne($repoPath, $rel);
        }
        return $out;
    }

    private function parseOne(string $repoPath, string $rel): ParsedFile
    {
        $pf = new ParsedFile();
        $pf->setPath($rel);
        $pf->setLanguage('php');

        $abs = rtrim($repoPath, '/') . '/' . ltrim($rel, '/');
        if (!is_readable($abs)) {
            $pf->setParseError(sprintf('file not readable: %s', $abs));
            return $pf;
        }

        $source = @file_get_contents($abs);
        if ($source === false) {
            $pf->setParseError(sprintf('file_get_contents failed: %s', $abs));
            return $pf;
        }

        try {
            $ast = $this->phpParser->parse($source);
        } catch (PhpParserError $e) {
            // Hard parse error — return empty ParsedFile with
            // parse_error set. The Go side's detectors will skip
            // this file's AST-driven checks but still apply the
            // universal walker-based ones.
            $pf->setParseError($e->getMessage());
            return $pf;
        }

        if ($ast === null) {
            $pf->setParseError('parser returned null AST');
            return $pf;
        }

        // Resolve namespaced names so use-statement imports resolve
        // to fully-qualified targets. NameResolver mutates nodes in
        // place; we run it once before our visitors look at the tree.
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $pf->setImports($this->collectImports($ast));
        $pf->setDecls($this->collectDecls($ast));
        $pf->setFunctions($this->collectFunctions($ast));
        $pf->setConcerns($this->collectConcerns($ast));

        return $pf;
    }

    /**
     * Walk Use_ statements (and grouped uses) to produce ImportRef
     * entries. Each `use Foo\Bar;` becomes one ImportRef; a grouped
     * `use Foo\{Bar, Baz};` produces two.
     *
     * @param Node[] $ast
     * @return ImportRef[]
     */
    private function collectImports(array $ast): array
    {
        $out = [];
        $finder = new NodeFinder();

        /** @var Node\Stmt\Use_[] $useNodes */
        $useNodes = $finder->findInstanceOf($ast, Node\Stmt\Use_::class);
        foreach ($useNodes as $useNode) {
            foreach ($useNode->uses as $useUse) {
                $out[] = $this->makeImport(
                    $useUse->name->toString(),
                    $useUse->alias?->toString() ?? '',
                    $useUse->getStartLine(),
                );
            }
        }

        // Group-use: `use Foo\{Bar, Baz};`. Each UseUse inside the
        // group already includes its base prefix in the resolved name
        // courtesy of NameResolver.
        /** @var Node\Stmt\GroupUse[] $groupUses */
        $groupUses = $finder->findInstanceOf($ast, Node\Stmt\GroupUse::class);
        foreach ($groupUses as $groupUse) {
            $prefix = $groupUse->prefix->toString();
            foreach ($groupUse->uses as $useUse) {
                $fullName = $prefix . '\\' . $useUse->name->toString();
                $out[] = $this->makeImport(
                    $fullName,
                    $useUse->alias?->toString() ?? '',
                    $useUse->getStartLine(),
                );
            }
        }

        return $out;
    }

    private function makeImport(string $path, string $alias, int $line): ImportRef
    {
        $imp = new ImportRef();
        $imp->setPath($path);
        $imp->setAlias($alias);
        $imp->setLine($line);
        return $imp;
    }

    /**
     * Walk top-level declarations. PHP's declaration kinds map to
     * the structural DeclKind enum as:
     *
     *   - Stmt\Function_   → "function"
     *   - Stmt\Class_      → "class"
     *   - Stmt\Interface_  → "interface"
     *   - Stmt\Trait_      → "trait" (PHP-specific; Go side treats as
     *                       "class" for portability — detectors keep
     *                       the original kind for richer findings)
     *   - Stmt\Enum_       → "enum" (PHP 8.1+)
     *   - Stmt\Const_      → "const"
     *
     * We only walk TOP-LEVEL declarations (the immediate children of
     * the file root, plus children of any top-level Namespace_
     * nodes). Methods inside classes are NOT decls here — they're
     * Functions (see collectFunctions).
     *
     * @param Node[] $ast
     * @return DeclRef[]
     */
    private function collectDecls(array $ast): array
    {
        $out = [];
        // Walk the file's top-level statements. Unwrap Namespace_
        // bodies so namespaced files produce the same DeclRef set as
        // un-namespaced ones.
        $top = [];
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                foreach ($node->stmts as $inner) {
                    $top[] = $inner;
                }
            } else {
                $top[] = $node;
            }
        }

        foreach ($top as $node) {
            $decl = $this->declFromNode($node);
            if ($decl !== null) {
                $out[] = $decl;
            }
        }
        return $out;
    }

    private function declFromNode(Node $node): ?DeclRef
    {
        $decl = new DeclRef();
        $decl->setLine($node->getStartLine());
        $decl->setEndLine($node->getEndLine());
        $decl->setExported(true); // PHP has no formal export; top-level decls are reachable.

        switch (true) {
            case $node instanceof Node\Stmt\Function_:
                $decl->setName($node->name->toString());
                $decl->setKind('function');
                return $decl;

            case $node instanceof Node\Stmt\Class_:
                if ($node->name === null) {
                    // Anonymous class at the file root — skip.
                    return null;
                }
                $decl->setName($node->name->toString());
                $decl->setKind('class');
                return $decl;

            case $node instanceof Node\Stmt\Interface_:
                $decl->setName($node->name->toString());
                $decl->setKind('interface');
                return $decl;

            case $node instanceof Node\Stmt\Trait_:
                $decl->setName($node->name->toString());
                $decl->setKind('trait');
                return $decl;

            case $node instanceof Node\Stmt\Enum_:
                $decl->setName($node->name->toString());
                $decl->setKind('enum');
                return $decl;

            case $node instanceof Node\Stmt\Const_:
                // A top-level `const FOO = 1, BAR = 2;` declares
                // multiple names. Return only the first here;
                // multi-name handling would require a list return
                // and complicate downstream code.
                if (!empty($node->consts)) {
                    $decl->setName($node->consts[0]->name->toString());
                    $decl->setKind('const');
                    return $decl;
                }
                return null;
        }
        return null;
    }

    /**
     * Walk every function-shaped definition in the file:
     *
     *   - Top-level Stmt\Function_ → is_method=false
     *   - Stmt\ClassMethod inside any Stmt\Class_/Interface_/Trait_
     *     → is_method=true
     *
     * Each produces a FunctionRef with line range, cyclomatic
     * complexity, language-specific + canonical hashes, and nested
     * block extraction.
     *
     * @param Node[] $ast
     * @return FunctionRef[]
     */
    private function collectFunctions(array $ast): array
    {
        $out = [];
        $finder = new NodeFinder();

        /** @var Node\Stmt\Function_[] $funcs */
        $funcs = $finder->findInstanceOf($ast, Node\Stmt\Function_::class);
        foreach ($funcs as $fn) {
            $out[] = $this->buildFunctionRef(
                $fn->name->toString(),
                $fn,
                isMethod: false,
                isExported: true,
            );
        }

        /** @var Node\Stmt\ClassLike[] $classLikes */
        $classLikes = $finder->find($ast, function (Node $n): bool {
            return $n instanceof Node\Stmt\ClassLike;
        });
        foreach ($classLikes as $class) {
            foreach ($class->getMethods() as $method) {
                $out[] = $this->buildFunctionRef(
                    $method->name->toString(),
                    $method,
                    isMethod: true,
                    isExported: !$method->isPrivate(),
                );
            }
        }

        return $out;
    }

    private function buildFunctionRef(
        string $name,
        Node\FunctionLike $fn,
        bool $isMethod,
        bool $isExported,
    ): FunctionRef {
        $ref = new FunctionRef();
        $ref->setName($name);
        $ref->setStartLine($fn->getStartLine());
        $ref->setEndLine($fn->getEndLine());
        $ref->setComplexity($this->cyclomaticComplexity($fn));
        $ref->setIsMethod($isMethod);
        $ref->setIsExported($isExported);
        $ref->setHash($this->hashFunctionBody($fn));
        $ref->setCanonicalHash($this->canonicalHashBody($fn));
        $ref->setBlocks($this->extractBlocks($fn));
        return $ref;
    }

    /**
     * Cyclomatic complexity (McCabe): start at 1, add 1 for each
     * decision point.
     *
     * Decision points in PHP:
     *   - if / elseif (each Else_ doesn't add — it's the negation
     *     of the if)
     *   - for, foreach, while, do-while
     *   - case (in switch / match)
     *   - catch
     *   - ternary, null-coalesce — deliberately NOT counted to
     *     match the Go and TS counterparts that also skip these
     */
    private function cyclomaticComplexity(Node\FunctionLike $fn): int
    {
        $score = 1;
        $finder = new NodeFinder();
        $finder->find([$fn], function (Node $n) use (&$score): bool {
            if ($n instanceof Node\Stmt\If_
                || $n instanceof Node\Stmt\ElseIf_
                || $n instanceof Node\Stmt\For_
                || $n instanceof Node\Stmt\Foreach_
                || $n instanceof Node\Stmt\While_
                || $n instanceof Node\Stmt\Do_
                || $n instanceof Node\Stmt\Case_
                || $n instanceof Node\Stmt\Catch_
                || $n instanceof Node\MatchArm
            ) {
                $score++;
            }
            return false; // NodeFinder's collector ignores return, but be explicit
        });
        return $score;
    }

    /**
     * Language-specific body hash. Walks the function body recording
     * one token per AST node, capturing PHP-flavored operator kinds.
     *
     * Skips:
     *   - Identifier names (so `function sum` and `function add`
     *     hash identically when bodies match)
     *   - Literal values (kind preserved, value dropped)
     */
    private function hashFunctionBody(Node\FunctionLike $fn): string
    {
        $tokens = [];
        $nodes = 0;
        $this->walkForHash($fn->getStmts() ?? [], $tokens, $nodes, canonical: false);
        if ($nodes <= 2) {
            return '';
        }
        return substr(sha1(implode(';', $tokens)), 0, self::HASH_BYTES * 2);
    }

    /**
     * Cross-language canonical hash. Same scheme but uses the
     * universal token vocabulary defined in
     * core/pkg/structural/lang/canonical.go.
     */
    private function canonicalHashBody(Node\FunctionLike $fn): string
    {
        $tokens = [];
        $nodes = 0;
        $this->walkForHash($fn->getStmts() ?? [], $tokens, $nodes, canonical: true);
        if ($nodes <= 2) {
            return '';
        }
        return substr(sha1(implode(';', $tokens)), 0, self::HASH_BYTES * 2);
    }

    /**
     * Walk a node list (or single node) producing a token stream for
     * hashing. canonical=true uses the cross-language vocabulary;
     * canonical=false uses PHP-specific node-class names.
     *
     * @param Node|Node[] $nodes
     */
    private function walkForHash(mixed $nodes, array &$tokens, int &$nodeCount, bool $canonical): void
    {
        if ($nodes instanceof Node) {
            $nodes = [$nodes];
        }
        if (!is_array($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }
            $nodeCount++;

            $tokens[] = $canonical
                ? $this->canonicalToken($node)
                : $this->phpToken($node);

            // Identifiers and literals carry no children worth
            // descending into for hashing purposes.
            if ($node instanceof Node\Identifier
                || $node instanceof Node\Name
                || $node instanceof Node\Scalar
            ) {
                continue;
            }

            // Recurse into sub-nodes via getSubNodeNames + reflection
            // of the public properties nikic/PHP-Parser uses.
            foreach ($node->getSubNodeNames() as $subName) {
                $sub = $node->{$subName};
                if ($sub instanceof Node) {
                    $this->walkForHash($sub, $tokens, $nodeCount, $canonical);
                } elseif (is_array($sub)) {
                    $this->walkForHash($sub, $tokens, $nodeCount, $canonical);
                }
            }
        }
    }

    /**
     * Map a PHP AST node to its language-specific token. Captures
     * operator kinds for BinaryOp / AssignOp / cast / unary nodes so
     * `+` and `-` hash differently.
     */
    private function phpToken(Node $node): string
    {
        switch (true) {
            case $node instanceof Node\Identifier:
            case $node instanceof Node\Name:
            case $node instanceof Node\VarLikeIdentifier:
                return 'I';
            case $node instanceof Node\Scalar\String_:
            case $node instanceof Node\Scalar\EncapsedStringPart:
                return 'L:STR';
            case $node instanceof Node\Scalar\LNumber:
            case $node instanceof Node\Scalar\DNumber:
                return 'L:NUM';
            case $node instanceof Node\Expr\ConstFetch:
                // true / false / null come through as ConstFetch.
                $name = strtolower($node->name->toString());
                if ($name === 'true' || $name === 'false') {
                    return 'L:BOOL';
                }
                if ($name === 'null') {
                    return 'L:NIL';
                }
                return get_class($node);
            case $node instanceof Node\Expr\BinaryOp:
                return 'BIN:' . $this->binaryOpSymbol($node);
            case $node instanceof Node\Expr\AssignOp:
                return 'ASN:' . $this->assignOpSymbol($node);
            case $node instanceof Node\Expr\UnaryMinus:
                return 'UN:-';
            case $node instanceof Node\Expr\UnaryPlus:
                return 'UN:+';
            case $node instanceof Node\Expr\BooleanNot:
                return 'UN:!';
            case $node instanceof Node\Expr\BitwiseNot:
                return 'UN:~';
        }
        return get_class($node);
    }

    /**
     * Map a PHP AST node onto the canonical token vocabulary shared
     * with the Go and TS parsers. See
     * core/pkg/structural/lang/canonical.go for the authoritative
     * list — keep this switch in sync as that file evolves.
     */
    private function canonicalToken(Node $node): string
    {
        switch (true) {
            case $node instanceof Node\Stmt\If_:
            case $node instanceof Node\Stmt\ElseIf_:
                return 'IF';
            case $node instanceof Node\Stmt\For_:
            case $node instanceof Node\Stmt\Foreach_:
            case $node instanceof Node\Stmt\While_:
            case $node instanceof Node\Stmt\Do_:
                return 'FOR';
            case $node instanceof Node\Stmt\Return_:
                return 'RETURN';
            case $node instanceof Node\Expr\Assign:
            case $node instanceof Node\Expr\AssignRef:
                return 'ASSIGN';
            case $node instanceof Node\Expr\PostInc:
            case $node instanceof Node\Expr\PreInc:
                return 'INC';
            case $node instanceof Node\Expr\PostDec:
            case $node instanceof Node\Expr\PreDec:
                return 'DEC';
            case $node instanceof Node\Stmt\Block:
            case $node instanceof Node\Stmt\Nop:
                return 'BLOCK';
            case $node instanceof Node\Stmt\Switch_:
            case $node instanceof Node\Expr\Match_:
                return 'SWITCH';
            case $node instanceof Node\Stmt\Break_:
                return 'BREAK';
            case $node instanceof Node\Stmt\Continue_:
                return 'CONTINUE';
            case $node instanceof Node\Stmt\TryCatch:
                return 'TRY';
            case $node instanceof Node\Stmt\Throw_:
            case $node instanceof Node\Expr\Throw_:
                return 'THROW';
            case $node instanceof Node\Stmt\Finally_:
                return 'DEFER';
            case $node instanceof Node\Expr\FuncCall:
            case $node instanceof Node\Expr\MethodCall:
            case $node instanceof Node\Expr\StaticCall:
            case $node instanceof Node\Expr\NullsafeMethodCall:
                return 'CALL';
            case $node instanceof Node\Expr\PropertyFetch:
            case $node instanceof Node\Expr\StaticPropertyFetch:
            case $node instanceof Node\Expr\NullsafePropertyFetch:
                return 'MEMBER';
            case $node instanceof Node\Expr\ArrayDimFetch:
                return 'INDEX';
            case $node instanceof Node\Identifier:
            case $node instanceof Node\Name:
            case $node instanceof Node\VarLikeIdentifier:
                return 'ID';
            case $node instanceof Node\Expr\New_:
                return 'NEW';
            case $node instanceof Node\Scalar\String_:
            case $node instanceof Node\Scalar\EncapsedStringPart:
                return 'LIT:STR';
            case $node instanceof Node\Scalar\LNumber:
            case $node instanceof Node\Scalar\DNumber:
                return 'LIT:NUM';
            case $node instanceof Node\Expr\ConstFetch:
                $name = strtolower($node->name->toString());
                if ($name === 'true' || $name === 'false') {
                    return 'LIT:BOOL';
                }
                if ($name === 'null') {
                    return 'LIT:NIL';
                }
                return 'NODE';
            case $node instanceof Node\Expr\BinaryOp:
                return 'BIN:' . $this->binaryOpSymbol($node);
            case $node instanceof Node\Expr\UnaryMinus:
                return 'UN:-';
            case $node instanceof Node\Expr\UnaryPlus:
                return 'UN:+';
            case $node instanceof Node\Expr\BooleanNot:
                return 'UN:!';
            case $node instanceof Node\Expr\BitwiseNot:
                return 'UN:~';
        }
        return 'NODE';
    }

    /**
     * Return the symbolic operator string for a BinaryOp node.
     * nikic/PHP-Parser uses separate classes per operator
     * (BinaryOp\Plus, BinaryOp\Minus, etc.); we map each to the
     * canonical operator string.
     */
    private function binaryOpSymbol(Node\Expr\BinaryOp $node): string
    {
        // BinaryOp::getOperatorSigil() exposes "+", "-", etc.
        return $node->getOperatorSigil();
    }

    private function assignOpSymbol(Node\Expr\AssignOp $node): string
    {
        return $node->getOperatorSigil();
    }

    /**
     * Walk the function body and produce a BlockRef per nested
     * if/else, for/foreach, while/do-while, switch case, try, catch,
     * and finally body. Each block gets both a language-specific
     * and a canonical hash so within-language and cross-language
     * block-duplicate detection consume the same data.
     *
     * Minimum size guard: blocks with ≤2 statements are skipped
     * (single-statement if-bodies like `return $x;` add no useful
     * DRY signal).
     *
     * @return BlockRef[]
     */
    private function extractBlocks(Node\FunctionLike $fn): array
    {
        $out = [];
        $finder = new NodeFinder();
        $body = $fn->getStmts() ?? [];

        $addBlock = function (?array $stmts, int $startLine, int $endLine, string $kind) use (&$out): void {
            if ($stmts === null || count($stmts) < self::MIN_BLOCK_STMTS) {
                return;
            }
            $synth = new Node\Stmt\Block(['stmts' => $stmts], [
                'startLine' => $startLine,
                'endLine' => $endLine,
            ]);
            // The synthetic block reuses the existing hashers by
            // wrapping the statement list in a Node\Stmt\Block.
            $hash = $this->hashStmts($stmts);
            $canon = $this->canonicalHashStmts($stmts);
            if ($hash === '' && $canon === '') {
                return;
            }
            $b = new BlockRef();
            $b->setKind($kind);
            $b->setStartLine($startLine);
            $b->setEndLine($endLine);
            $b->setHash($hash);
            $b->setCanonicalHash($canon);
            $out[] = $b;
        };

        $finder->find($body, function (Node $node) use ($addBlock): bool {
            switch (true) {
                case $node instanceof Node\Stmt\If_:
                    $addBlock($node->stmts, $node->getStartLine(), $node->getEndLine(), 'if');
                    foreach ($node->elseifs as $elseif) {
                        $addBlock($elseif->stmts, $elseif->getStartLine(), $elseif->getEndLine(), 'elseif');
                    }
                    if ($node->else !== null) {
                        $addBlock($node->else->stmts, $node->else->getStartLine(), $node->else->getEndLine(), 'else');
                    }
                    break;
                case $node instanceof Node\Stmt\For_:
                case $node instanceof Node\Stmt\Foreach_:
                case $node instanceof Node\Stmt\While_:
                case $node instanceof Node\Stmt\Do_:
                    $addBlock($node->stmts, $node->getStartLine(), $node->getEndLine(), 'for');
                    break;
                case $node instanceof Node\Stmt\Switch_:
                    foreach ($node->cases as $case) {
                        $addBlock($case->stmts, $case->getStartLine(), $case->getEndLine(), 'case');
                    }
                    break;
                case $node instanceof Node\Stmt\TryCatch:
                    $addBlock($node->stmts, $node->getStartLine(), $node->getEndLine(), 'try');
                    foreach ($node->catches as $catch) {
                        $addBlock($catch->stmts, $catch->getStartLine(), $catch->getEndLine(), 'catch');
                    }
                    if ($node->finally !== null) {
                        $addBlock($node->finally->stmts, $node->finally->getStartLine(), $node->finally->getEndLine(), 'finally');
                    }
                    break;
            }
            return false;
        });
        return $out;
    }

    private function hashStmts(array $stmts): string
    {
        $tokens = [];
        $nodes = 0;
        $this->walkForHash($stmts, $tokens, $nodes, canonical: false);
        if ($nodes <= 2) {
            return '';
        }
        return substr(sha1(implode(';', $tokens)), 0, self::HASH_BYTES * 2);
    }

    private function canonicalHashStmts(array $stmts): string
    {
        $tokens = [];
        $nodes = 0;
        $this->walkForHash($stmts, $tokens, $nodes, canonical: true);
        if ($nodes <= 2) {
            return '';
        }
        return substr(sha1(implode(';', $tokens)), 0, self::HASH_BYTES * 2);
    }

    /**
     * Classify call sites and imports into ConcernEvidenceRef
     * entries. Mirrors `collectConcerns` in workers/ts/src/parser.ts;
     * the categories and tables align with the Laravel profile's
     * StateAPIs / NetworkAPIs lists in core/pkg/structural/framework/laravel.go.
     *
     * Coverage today:
     *   - state: Session::/Cache::/Config::/Auth:: static calls and
     *     the session()/cache()/config()/auth() helper functions
     *   - network: Http::, Guzzle method calls, curl_*, file_get_contents
     *   - dataaccess: DB::, Eloquent query methods, PDO method calls
     *   - io: file operations (fopen, fread, fwrite, file_put_contents,
     *     unlink, mkdir, rmdir)
     *   - config: $_ENV, $_SERVER reads, env() calls, getenv()
     *   - business: functions whose cyclomatic complexity ≥ 8
     *
     * Transport intentionally omitted today — PHP's request handling
     * spans too many shapes (Laravel routes/web.php, Symfony
     * annotations, plain PHP $_GET handling) for a clean classifier
     * without false positives. The Laravel routing profile catches
     * the file-level "this is routing" signal instead.
     *
     * @param Node[] $ast
     * @return ConcernEvidenceRef[]
     */
    private function collectConcerns(array $ast): array
    {
        $out = [];
        $finder = new NodeFinder();

        // Walk every call expression once.
        $finder->find($ast, function (Node $node) use (&$out): bool {
            // Static calls: ClassName::method(...)
            if ($node instanceof Node\Expr\StaticCall) {
                $cls = $node->class instanceof Node\Name ? $node->class->toString() : '';
                $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
                $sym = $cls . '::' . $method;
                $line = $node->getStartLine();
                $this->classifyCall($sym, $line, $out);
                return false;
            }

            // Method calls: $obj->method(...)
            if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
                $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
                // We can't always know the receiver's class without
                // type resolution, but the method NAME alone is often
                // enough for the concern classifier (db query
                // methods, http client methods).
                $this->classifyMethodCall($method, $node->getStartLine(), $out);
                return false;
            }

            // Function calls: foo(...)
            if ($node instanceof Node\Expr\FuncCall) {
                if ($node->name instanceof Node\Name) {
                    $sym = $node->name->toString();
                    $this->classifyCall($sym, $node->getStartLine(), $out);
                }
                return false;
            }

            // Superglobal access: $_ENV[...], $_SERVER[...]
            if ($node instanceof Node\Expr\ArrayDimFetch
                && $node->var instanceof Node\Expr\Variable
                && is_string($node->var->name)
            ) {
                $varName = $node->var->name;
                if ($varName === '_ENV' || $varName === '_SERVER') {
                    $out[] = $this->makeConcern('config', $node->getStartLine(), '$' . $varName, 'superglobal read');
                }
                return false;
            }

            return false;
        });

        // Business: high-complexity functions contribute one
        // evidence each. We can't reuse collectFunctions's output
        // here (would create a circular pass), so re-walk for
        // function-like nodes with their complexity.
        $finder->find($ast, function (Node $node) use (&$out): bool {
            if ($node instanceof Node\Stmt\Function_
                || $node instanceof Node\Stmt\ClassMethod
            ) {
                if ($this->cyclomaticComplexity($node) >= self::BUSINESS_COMPLEXITY) {
                    $name = $node->name instanceof Node\Identifier
                        ? $node->name->toString()
                        : '<anonymous>';
                    $out[] = $this->makeConcern('business', $node->getStartLine(), $name, 'complex function');
                }
            }
            return false;
        });

        return $out;
    }

    private function classifyCall(string $sym, int $line, array &$out): void
    {
        // Laravel facades — static state / config / auth.
        if (in_array($sym, [
            'Session::get', 'Session::put', 'Session::has', 'Session::flush',
            'Cache::get', 'Cache::put', 'Cache::has', 'Cache::forget',
            'Config::get', 'Config::set',
            'Auth::user', 'Auth::check', 'Auth::login', 'Auth::logout',
        ], true)) {
            $cat = str_starts_with($sym, 'Config::') ? 'config' : 'state';
            $out[] = $this->makeConcern($cat, $line, $sym);
            return;
        }

        // Helper functions equivalent to the facades.
        if (in_array($sym, ['session', 'cache', 'auth'], true)) {
            $out[] = $this->makeConcern('state', $line, $sym . '()');
            return;
        }
        if (in_array($sym, ['config', 'env', 'getenv'], true)) {
            $out[] = $this->makeConcern('config', $line, $sym . '()');
            return;
        }

        // Network: Laravel Http facade methods.
        if (str_starts_with($sym, 'Http::')) {
            $out[] = $this->makeConcern('network', $line, $sym);
            return;
        }

        // Data access: Laravel DB facade methods.
        if (str_starts_with($sym, 'DB::')) {
            $out[] = $this->makeConcern('dataaccess', $line, $sym);
            return;
        }

        // IO: stdlib file functions.
        $ioFuncs = [
            'fopen', 'fclose', 'fread', 'fwrite', 'fgets', 'fputs',
            'file_get_contents', 'file_put_contents', 'file_exists',
            'is_file', 'is_dir', 'unlink', 'rename', 'copy',
            'mkdir', 'rmdir', 'opendir', 'readdir', 'closedir',
            'glob', 'scandir', 'realpath',
        ];
        if (in_array($sym, $ioFuncs, true)) {
            // file_get_contents on a URL is network; on a path is IO.
            // We can't disambiguate without runtime info — pick the
            // safer (more common) classification: file_get_contents
            // for URLs is usage that the network classifier picks up
            // on the receiver side (Guzzle, Http facade), so we
            // accept the IO classification here.
            $out[] = $this->makeConcern('io', $line, $sym);
            return;
        }

        // Network: curl_*.
        if (str_starts_with($sym, 'curl_')) {
            $out[] = $this->makeConcern('network', $line, $sym);
            return;
        }
    }

    private function classifyMethodCall(string $method, int $line, array &$out): void
    {
        // Guzzle / Symfony HttpClient instance methods (the
        // receiver might be $client, $http, $api, etc.).
        $httpMethods = ['get', 'post', 'put', 'delete', 'patch', 'head', 'options', 'send', 'request'];
        if (in_array($method, $httpMethods, true)) {
            $out[] = $this->makeConcern('network', $line, '->' . $method);
            return;
        }

        // Eloquent / PDO query methods (the receiver is typically a
        // Model class or PDO instance).
        $queryMethods = ['where', 'find', 'first', 'get', 'all', 'pluck', 'select', 'orderBy',
            'create', 'update', 'delete', 'save', 'query', 'prepare', 'execute', 'fetch', 'fetchAll'];
        if (in_array($method, $queryMethods, true)) {
            // Filter out the duplicate with httpMethods above (get,
            // delete) — when both could apply, we default to
            // data access. This is heuristic; a more precise version
            // requires type-aware resolution.
            if (in_array($method, ['get', 'delete'], true)) {
                return;
            }
            $out[] = $this->makeConcern('dataaccess', $line, '->' . $method);
            return;
        }
    }

    private function makeConcern(string $category, int $line, string $symbol, string $note = ''): ConcernEvidenceRef
    {
        $c = new ConcernEvidenceRef();
        $c->setCategory($category);
        $c->setLine($line);
        $c->setSymbol($symbol);
        $c->setNote($note);
        return $c;
    }
}
