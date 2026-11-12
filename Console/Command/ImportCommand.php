<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Console\Command;

use BS\SalesTransfer\Model\Transfer\Importer;
use BS\SalesTransfer\Model\Transfer\ImportResult;
use BS\SalesTransfer\Model\Transfer\SalesDocument;
use BS\SalesTransfer\Model\Validation\ImportBehavior;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCommand extends Command
{
    public function __construct(private readonly Importer $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bs:sales:import');
        $this->setDescription('Import orders, invoices, shipments and credit memos from a .jsonl file.');
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path of the .jsonl file');
        $this->addOption(
            'behavior',
            null,
            InputOption::VALUE_REQUIRED,
            'skip leaves an existing order unchanged; replace overwrites that order on this store',
            SalesDocument::BEHAVIOR_SKIP
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = trim((string) $input->getOption('file'));
        if ($file === '') {
            $output->writeln('<error>Provide --file.</error>');
            return Cli::RETURN_FAILURE;
        }

        $path = str_starts_with($file, '/') ? $file : BP . '/' . ltrim($file, '/');
        if (!is_file($path)) {
            $output->writeln('<error>File not found.</error>');
            return Cli::RETURN_FAILURE;
        }
        $behavior = (string) $input->getOption('behavior');
        if (!ImportBehavior::isValid($behavior)) {
            $output->writeln('<error>Invalid import behavior. Use skip or replace.</error>');
            return Cli::RETURN_FAILURE;
        }

        $progress = null;
        try {
            $total = $this->importer->count($path);
            if ($total > 0) {
                $progress = new ProgressBar($output, $total);
                $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
                $progress->setMessage('');
                $progress->start();
            }
            $result = $this->importer->import(
                $path,
                $behavior,
                static function (ImportResult $result, string $label) use ($progress): void {
                    if ($progress === null) {
                        return;
                    }
                    $progress->setMessage($label);
                    $progress->advance();
                }
            );
        } catch (\Throwable $exception) {
            if ($progress !== null) {
                $progress->finish();
                $output->writeln('');
            }
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
        if ($progress !== null) {
            $progress->finish();
            $output->writeln('');
        }

        $output->writeln(sprintf(
            '<info>Import finished. Imported: %d. Already present: %d. Failed: %d.</info>',
            $result->imported,
            $result->skipped,
            $result->failed
        ));
        foreach ($result->errors as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }
        foreach ($result->warnings as $warning) {
            $output->writeln('<comment>' . $warning . '</comment>');
        }

        return $result->failed > 0 ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }
}
