<?php

declare(strict_types=1);

namespace BS\SalesTransfer\Console\Command;

use BS\SalesTransfer\Model\Transfer\ExportFilter;
use BS\SalesTransfer\Model\Transfer\Exporter;
use BS\SalesTransfer\Model\Validation\DateRange;
use BS\SalesTransfer\Model\Validation\StoreCode;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends Command
{
    public function __construct(
        private readonly Exporter $exporter,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bs:sales:export');
        $this->setDescription('Export orders, invoices, shipments and credit memos to a .jsonl file.');
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path of the .jsonl file');
        $this->addOption('store', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Store code. Repeat for more than one store. Omit to export every store.');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date (YYYY-MM-DD), in the store timezone');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date (YYYY-MM-DD), in the store timezone');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = trim((string) $input->getOption('file'));
        if ($file === '') {
            $output->writeln('<error>Provide --file.</error>');
            return Cli::RETURN_FAILURE;
        }

        try {
            $from = $this->rawDate($input->getOption('from'));
            $to = $this->rawDate($input->getOption('to'));
            if (!DateRange::isOrdered($from, $to)) {
                throw new LocalizedException(__('The start date must be on or before the end date.'));
            }
            $path = $this->absolutePath($file);
            $count = $this->exporter->export($path, new ExportFilter(
                $this->storeIds((array) $input->getOption('store')),
                $this->utcBoundary($from, false),
                $this->utcBoundary($to, true)
            ), static function (int $exported, string $incrementId) use ($output): void {
                if ($exported % 100 === 0) {
                    $output->writeln(sprintf('%d orders exported. Last: %s', $exported, $incrementId));
                }
            });
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $output->writeln(sprintf('<info>%d orders written to %s</info>', $count, $path));

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param string[] $codes
     * @return int[]
     */
    private function storeIds(array $codes): array
    {
        $ids = [];
        foreach ($codes as $code) {
            $code = trim($code);
            if ($code === '') {
                continue;
            }
            if (!StoreCode::isValid($code)) {
                throw new LocalizedException(__('Invalid store code.'));
            }
            $store = $this->storeManager->getStore($code);
            if (!$store->getId()) {
                throw new LocalizedException(__('Store "%1" does not exist.', $code));
            }
            $ids[] = (int) $store->getId();
        }

        return $ids;
    }

    private function rawDate(mixed $date): ?string
    {
        $date = trim((string) $date);

        return $date === '' ? null : $date;
    }

    private function utcBoundary(mixed $date, bool $endOfDay): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }
        if (!DateRange::isIsoDate($date)) {
            throw new LocalizedException(__('Use the date format YYYY-MM-DD.'));
        }
        $time = $endOfDay ? '23:59:59' : '00:00:00';

        return $this->timezone->convertConfigTimeToUtc($date . ' ' . $time);
    }

    private function absolutePath(string $file): string
    {
        if (!str_starts_with($file, '/')) {
            return BP . '/' . ltrim($file, '/');
        }

        return $file;
    }
}
