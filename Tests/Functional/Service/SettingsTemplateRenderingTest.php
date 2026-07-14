<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Functional\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Renders Settings.html the way the controller does (StandaloneView, absolute
 * EXT: path). Covers the prompt/content form section and the CSS-only tabs — a
 * Fluid error or a broken checkbox-toggle only surfaces at render time, and the
 * template is otherwise uncovered by the harness.
 */
final class SettingsTemplateRenderingTest extends FunctionalTestCase
{
    protected $testExtensionsToLoad = ['typo3conf/ext/ai_proofread'];

    private function render(array $prompt): string
    {
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplatePathAndFilename(
            GeneralUtility::getFileAbsFileName('EXT:ai_proofread/Resources/Private/Templates/Review/Settings.html')
        );
        $view->assignMultiple([
            'formUrl' => '/typo3/module/web/aiproofread?token=x',
            'presets' => [
                ['key' => 'opus-4.8', 'label' => 'Claude Opus 4.8', 'active' => true, 'details' => ['model' => 'anthropic/claude-opus-4.8', 'chips' => ['Reasoning']]],
                ['key' => 'custom', 'label' => 'Benutzerdefiniert', 'active' => false, 'details' => ['model' => '', 'chips' => []]],
            ],
            'custom' => ['model' => 'x/y', 'reasoning' => true, 'maxTokens' => 0, 'structuredOutput' => 'json_schema', 'pinProvider' => ''],
            'structuredOutputOptions' => [
                ['value' => 'json_schema', 'label' => 'JSON-Schema', 'selected' => true],
                ['value' => 'json_object', 'label' => 'JSON-Objekt', 'selected' => false],
                ['value' => 'prompt', 'label' => 'Nur Prompt', 'selected' => false],
            ],
            'prompt' => $prompt,
        ]);
        return $view->render();
    }

    public function testRendersPromptSectionWithGenderEnabled(): void
    {
        $html = $this->render([
            'siteDescription' => 'eine Bäckerei',
            'extraPromptInstructions' => 'Korrigiere NIE Anführungszeichen.',
            'enableGenderInclusiveLanguage' => true,
            'genderInclusiveStyle' => 'Doppelpunkt (z.B. Nutzer:innen)',
        ]);

        // CSS-only tabs: both radios + panels render, the model tab is default-checked,
        // and "Aktives Modell" is renamed to "KI-Modell".
        self::assertStringContainsString('id="aiproofread-tab-model" checked="checked"', $html);
        self::assertStringContainsString('id="aiproofread-tab-prompt"', $html);
        self::assertStringContainsString('>KI-Modell</label>', $html);
        self::assertStringContainsString('>Prompt</label>', $html);
        self::assertStringContainsString('aiproofread-tabpanel-model', $html);
        self::assertStringContainsString('aiproofread-tabpanel-prompt', $html);

        // The preset radio (existing) still renders alongside the new section.
        self::assertStringContainsString('name="preset"', $html);

        self::assertStringContainsString('name="siteDescription"', $html);
        self::assertStringContainsString('value="eine Bäckerei"', $html);
        // Textarea content (not a value attribute).
        self::assertStringContainsString('>Korrigiere NIE Anführungszeichen.</textarea>', $html);
        self::assertStringContainsString('value="Doppelpunkt (z.B. Nutzer:innen)"', $html);
        // Checkbox reflects the saved "on".
        self::assertStringContainsString('name="enableGenderInclusiveLanguage" value="1" checked="checked"', $html);
    }

    public function testGenderCheckboxUncheckedWhenDisabled(): void
    {
        $html = $this->render([
            'siteDescription' => '',
            'extraPromptInstructions' => '',
            'enableGenderInclusiveLanguage' => false,
            'genderInclusiveStyle' => '',
        ]);

        self::assertStringContainsString('name="enableGenderInclusiveLanguage" value="1"', $html);
        self::assertStringNotContainsString('name="enableGenderInclusiveLanguage" value="1" checked="checked"', $html);
    }
}
