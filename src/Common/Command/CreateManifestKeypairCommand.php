<?php

/**
 * CLI command that generates an ed25519 keypair for manifest signing.
 *
 * The secret key stays on the signing machine (maintainer workstation now,
 * CI secret later) and must never be deployed to a server. The public key is
 * what gets pinned in verifying code and published in the repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2026 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Common\Command;

use OpenEMR\FileManifest\ManifestKeypair;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CreateManifestKeypairCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('openemr:create-manifest-keypair')
            ->setDescription('Generate an ed25519 keypair for signing file-integrity manifests')
            ->setDefinition(
                new InputDefinition([
                    new InputOption('secret-out', null, InputOption::VALUE_REQUIRED, 'Path to write the base64 secret key', 'manifest-signing.key'),
                    new InputOption('public-out', null, InputOption::VALUE_REQUIRED, 'Path to write the base64 public key', 'manifest-signing.pub'),
                    new InputOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing key files'),
                ])
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $secretOut = $input->getOption('secret-out');
        $publicOut = $input->getOption('public-out');
        if (!is_string($secretOut) || $secretOut === '' || !is_string($publicOut) || $publicOut === '') {
            $io->error('Both --secret-out and --public-out must be file paths.');
            return Command::FAILURE;
        }

        $force = $input->getOption('force') === true;
        foreach ([$secretOut, $publicOut] as $path) {
            if (!$force && file_exists($path)) {
                $io->error(sprintf('"%s" already exists. Pass --force to overwrite.', $path));
                return Command::FAILURE;
            }
        }

        $keypair = ManifestKeypair::generate();

        if (file_put_contents($secretOut, $keypair->secretKeyBase64 . "\n") === false) {
            $io->error(sprintf('Failed to write secret key to "%s".', $secretOut));
            return Command::FAILURE;
        }
        if (!chmod($secretOut, 0o600)) {
            $io->warning(sprintf('Could not restrict permissions on "%s" — set it to 0600 manually.', $secretOut));
        }
        if (file_put_contents($publicOut, $keypair->publicKeyBase64 . "\n") === false) {
            $io->error(sprintf('Failed to write public key to "%s".', $publicOut));
            return Command::FAILURE;
        }

        $io->definitionList(
            ['Secret key' => $secretOut . ' (mode 0600 — keep on the signing machine only)'],
            ['Public key' => $publicOut],
            ['Public key value' => $keypair->publicKeyBase64],
        );
        $io->success('Keypair generated. Publish the public key; never deploy the secret key to a server.');
        return Command::SUCCESS;
    }
}
