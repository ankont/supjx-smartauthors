<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Mocks/InMemoryPolicyRepository.php';
require_once __DIR__ . '/Mocks/MockCategoryTable.php';
require_once __DIR__ . '/Mocks/TestablePersonalOwnerBinder.php';
require_once __DIR__ . '/Domain/PolicyResolverTest.php';
require_once __DIR__ . '/Domain/AccessServiceTest.php';
require_once __DIR__ . '/Domain/PersonalOwnerBinderTest.php';
require_once __DIR__ . '/Domain/AccessServicePhase2Test.php';
require_once __DIR__ . '/Field/CategoryeditFieldPrefixTest.php';
require_once __DIR__ . '/Runtime/ArticlesModelQueryDecorationTest.php';
require_once __DIR__ . '/Runtime/NewArticleStateEnforcementTest.php';
require_once __DIR__ . '/Runtime/MVCComponentConventionsTest.php';
require_once __DIR__ . '/Runtime/FrontendDirectFormTest.php';

use SuperSoft\Plugin\System\SmartAuthors\Tests\Domain\AccessServicePhase2Test;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Domain\AccessServiceTest;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Domain\PersonalOwnerBinderTest;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Domain\PolicyResolverTest;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Field\CategoryeditFieldPrefixTest;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Runtime\ArticlesModelQueryDecorationTest;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Runtime\FrontendDirectFormTest;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Runtime\MVCComponentConventionsTest;
use SuperSoft\Plugin\System\SmartAuthors\Tests\Runtime\NewArticleStateEnforcementTest;

echo "============================================================\n";
echo "SuperSoftJx SmartAuthors (plg_system_smartauthors) - Test Suite\n";
echo "Target: Joomla 5.4.x / 6.x | Phase 1 & Phase 2\n";
echo "============================================================\n\n";

$suites = [
    'PolicyResolver & Inheritance (Phase 1: Cases A, B, C, R)' => new PolicyResolverTest(),
    'AccessService & Domain Authorization (Phase 1: Cases D, E, F, G, H, I)' => new AccessServiceTest(),
    'PersonalOwnerBinder & Lifecycle (Phase 1: Cases J, K, L, M, N, O, P, Q)' => new PersonalOwnerBinderTest(),
    'AccessService Phase 2 (New Article State, Actor Categories, Visibility != Edit)' => new AccessServicePhase2Test(),
    'CategoryeditField (Prefix Registration, Precedence, Subclass Integrity)' => new CategoryeditFieldPrefixTest(),
    'ArticlesModel Query Decoration (Composite WHERE, Unified Actor Visibility)' => new ArticlesModelQueryDecorationTest(),
    'New Article State Enforcement (State None -> 0, Trashed Option Removed, Move Integrity)' => new NewArticleStateEnforcementTest(),
    'MVC Component Conventions (com_content option, context, and naming integrity)' => new MVCComponentConventionsTest(),
    'Frontend Direct Form & Create Authorization (Path A direct view=form, Path B edit, New Article create)' => new FrontendDirectFormTest(),
];


$passedSuites = 0;
$totalSuites = count($suites);

foreach ($suites as $name => $suite) {
    echo "Running Suite: {$name}...\n";
    try {
        $suite->runAll();
        echo "Suite {$name}: OK\n\n";
        $passedSuites++;
    } catch (\Throwable $e) {
        echo "\n[FAIL] Exception in {$name}: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n\n";
        exit(1);
    }
}

echo "============================================================\n";
echo "ALL TESTS PASSED! ({$passedSuites}/{$totalSuites} suites passed cleanly)\n";
echo "============================================================\n";
exit(0);
