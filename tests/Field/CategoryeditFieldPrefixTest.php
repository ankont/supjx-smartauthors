<?php

declare(strict_types=1);

namespace SuperSoft\Plugin\System\SmartAuthors\Tests\Field;

use Joomla\CMS\Form\FormHelper;
use SuperSoft\Plugin\System\SmartAuthors\Field\CategoryeditField;

class CategoryeditFieldPrefixTest
{
    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    public function runAll(): void
    {
        $this->testFieldPrefixPrecedence();
        $this->testCategoryeditFieldInstantiation();
    }

    /**
     * Point 3: Verify that after adding our prefix, loadFieldType resolves to our class.
     */
    public function testFieldPrefixPrecedence(): void
    {
        // Core initial state: Joomla\Component\Categories\Administrator\Field registered
        FormHelper::$prefixes['field'] = [
            'Joomla\\Component\\Categories\\Administrator\\Field',
            'Joomla\\CMS\\Form\\Field',
        ];

        // Plugin registers its prefix
        FormHelper::addFieldPrefix('SuperSoft\\Plugin\\System\\SmartAuthors\\Field');

        // Confirm our prefix is at index 0
        $this->assert(
            FormHelper::$prefixes['field'][0] === 'SuperSoft\\Plugin\\System\\SmartAuthors\\Field',
            'SmartAuthors field prefix must be at index 0 of FormHelper::$prefixes["field"]'
        );

        // Resolve class name for 'categoryedit'
        $resolvedClass = FormHelper::loadFieldType('categoryedit');

        $this->assert(
            $resolvedClass === CategoryeditField::class,
            "Resolved field class must be " . CategoryeditField::class . ", got: " . var_export($resolvedClass, true)
        );

        echo "  [PASS] Phase 2 Test: CategoryeditField Prefix Registration & Precedence\n";
    }

    /**
     * Verify that CategoryeditField instantiates and extends CoreCategoryeditField properly.
     */
    public function testCategoryeditFieldInstantiation(): void
    {
        $field = new CategoryeditField();

        $this->assert(
            $field instanceof \Joomla\Component\Categories\Administrator\Field\CategoryeditField,
            'SmartAuthors CategoryeditField must be an instance of Joomla core CategoryeditField'
        );

        echo "  [PASS] Phase 2 Test: CategoryeditField Subclass & Hierarchy Integrity\n";
    }
}
