<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Integration\Scripts;

use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

use function chmod;
use function copy;
use function dirname;
use function explode;
use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function trim;
use function uniqid;

/**
 * Runs the version script against a scratch repository with a bare remote.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-memories/
 */
#[CoversNothing]
final class CreateVersionTest extends TestCase
{
    private string $workDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/create-version-' . uniqid('', true);

        $remote     = $this->workDir . '/remote.git';
        $repository = $this->workDir . '/repository';

        mkdir($repository . '/scripts', 0o777, true);

        copy(dirname(__DIR__, 3) . '/scripts/create-version', $repository . '/scripts/create-version');
        chmod($repository . '/scripts/create-version', 0o755);

        file_put_contents($repository . '/version', "1.0.0\n");

        // Signing needs a key, so the scratch repository signs through a stub that reports a created signature.
        $signer = $this->workDir . '/signer';

        file_put_contents(
            $signer,
            "#!/bin/sh\ncat >/dev/null\necho '[GNUPG:] SIG_CREATED D 1 8 00 1 0 FP' >&2\n"
            . "printf '%s\\n' '-----BEGIN PGP SIGNATURE-----' '' 'c3R1Yg==' '-----END PGP SIGNATURE-----'\n"
        );
        chmod($signer, 0o755);

        $this->git(['init', '--quiet', '--bare', $remote], $this->workDir);
        $this->git(['init', '--quiet', '--initial-branch=main'], $repository);
        $this->git(['config', 'user.name', 'Version Test'], $repository);
        $this->git(['config', 'user.email', 'version-test@example.invalid'], $repository);
        $this->git(['config', 'commit.gpgSign', 'false'], $repository);
        $this->git(['config', 'tag.gpgSign', 'false'], $repository);
        $this->git(['config', 'gpg.program', $signer], $repository);
        $this->git(['config', 'user.signingkey', 'test'], $repository);
        $this->git(['config', 'push.followTags', 'true'], $repository);
        $this->git(['remote', 'add', 'origin', $remote], $repository);
        $this->git(['add', 'version', 'scripts'], $repository);
        $this->git(['commit', '--quiet', '-m', 'First commit'], $repository);
        $this->git(['push', '--quiet', '-u', 'origin', 'main'], $repository);

        // The leftover tag is made after the first push, so that only the script can send it to the remote.
        $this->git(['tag', '-a', '0.9.0', '-m', 'Leftover tag'], $repository);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->workDir]))->mustRun();

        parent::tearDown();
    }

    /**
     * A refused push of the branch stops the script before the tag is sent. The tag stays local, because a tag that
     * reached the remote cannot be removed again where tags are immutable.
     */
    #[Test]
    public function keepsTheTagLocalWhenThePushOfTheBranchIsRefused(): void
    {
        $this->installRemoteThatRefusesTheBranch();

        $status = $this->runScript("1.0.1\n");

        self::assertNotSame(0, $status);
        self::assertSame('', $this->git(['tag', '--list', '1.0.1'], $this->workDir . '/remote.git'));
        self::assertSame('1.0.1', $this->git(['tag', '--list', '1.0.1'], $this->workDir . '/repository'));
    }

    /**
     * The release commit carries a sign-off and the title of a release, the tag is an annotated tag, and the remote
     * receives the branch and only the tag of the release. A leftover local tag stays local although the setting that
     * follows tags is on.
     */
    #[Test]
    public function publishesTheReleaseCommitAndOnlyTheReleaseTag(): void
    {
        $status = $this->runScript("1.0.1\n");

        self::assertSame(0, $status);

        $remote = $this->workDir . '/remote.git';

        self::assertSame([], $this->refs($remote, 'refs/tags/0.9.0'));
        self::assertSame('0.9.0', $this->git(['tag', '--list', '0.9.0'], $this->workDir . '/repository'));
        self::assertSame(['refs/tags/1.0.1'], $this->refs($remote, 'refs/tags/1.0.1'));
        self::assertSame('tag', $this->git(['cat-file', '-t', '1.0.1'], $remote));

        $message = $this->git(['log', '-1', '--format=%B', 'main'], $remote);

        self::assertStringStartsWith('Release 1.0.1', $message);
        self::assertStringContainsString('Signed-off-by: Version Test <version-test@example.invalid>', $message);
    }

    /**
     * Makes the bare remote refuse every update of main, the way a ruleset that waits for scan results does. The
     * check runs per reference, so the update of a tag in the same push would pass.
     */
    private function installRemoteThatRefusesTheBranch(): void
    {
        $hook = $this->workDir . '/remote.git/hooks/update';

        file_put_contents(
            $hook,
            "#!/bin/sh\nif [ \"\$1\" = refs/heads/main ]; then\n    echo 'refused by test' >&2\n    exit 1\nfi\nexit 0\n"
        );
        chmod($hook, 0o755);
    }

    /**
     * Runs the script in the scratch repository and returns its exit status.
     *
     * @param string $input The text that the script reads from its input
     */
    private function runScript(string $input): int
    {
        $process = new Process(
            ['bash', $this->workDir . '/repository/scripts/create-version'],
            $this->workDir . '/repository',
            ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $this->workDir],
            $input
        );

        $process->run();

        return (int) $process->getExitCode();
    }

    /**
     * Lists the references of a repository that match a pattern.
     *
     * @return list<string> The reference names
     */
    private function refs(string $repository, string $pattern): array
    {
        $refs = $this->git(['for-each-ref', '--format=%(refname)', $pattern], $repository);

        return $refs === '' ? [] : explode("\n", $refs);
    }

    /**
     * Runs git and fails the test when the command fails.
     *
     * @param list<string> $arguments The arguments of the git command
     * @param string       $directory The directory to run it in
     *
     * @return string The trimmed output
     */
    private function git(array $arguments, string $directory): string
    {
        $process = new Process(['git', ...$arguments], $directory, ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $this->workDir]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
