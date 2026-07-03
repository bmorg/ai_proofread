<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Build;

use Composer\Script\Event;

/**
 * Links the extension into the test-instance web dir
 * (.Build/public/typo3conf/ext/ai_proofread) with an ABSOLUTE symlink target.
 *
 * Replaces typo3/testing-framework's deprecated ExtensionTestEnvironment, whose
 * relative link breaks when .Build is itself a symlink (as on this dev box,
 * where .Build must live on the VM's native filesystem — see CLAUDE.md): the
 * relative target resolves against the link's real parent chain and points at
 * the build cache instead of the project, sending TYPO3's classic-mode classmap
 * scan through all of core+vendor via a symlink cycle (a 20+ minute boot).
 *
 * All paths are derived from composer's own config, never from the working
 * directory — invoking `composer dump-autoload` from a subdirectory must fail
 * loudly rather than plant a wrong link (observed once; cost an hour).
 */
final class ExtensionTestLink
{
    public static function postAutoloadDump(Event $event): void
    {
        // vendor-dir is <project>/.Build/vendor, absolutized by composer
        // against the composer.json location (cwd-independent).
        $vendorDir = (string)$event->getComposer()->getConfig()->get('vendor-dir');
        $projectRoot = dirname($vendorDir, 2);
        if (!is_file($projectRoot . '/ext_emconf.php')) {
            throw new \RuntimeException(
                'Refusing to link: ' . $projectRoot . ' does not look like the extension root.',
                1751540001
            );
        }

        $extDir = $projectRoot . '/.Build/public/typo3conf/ext';
        $link = $extDir . '/ai_proofread';

        if (!is_dir($extDir) && !mkdir($extDir, 0775, true) && !is_dir($extDir)) {
            throw new \RuntimeException('Could not create ' . $extDir, 1751540002);
        }
        if (is_link($link)) {
            unlink($link);
        } elseif (file_exists($link)) {
            throw new \RuntimeException(
                $link . ' exists and is not a symlink — refusing to replace it.',
                1751540003
            );
        }
        if (!symlink($projectRoot, $link)) {
            throw new \RuntimeException('Could not link ' . $projectRoot . ' to ' . $link, 1751540004);
        }
    }
}
