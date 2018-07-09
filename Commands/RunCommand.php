<?php

namespace Prod2Testing\Commands;

use Doctrine\DBAL\Connection;
use Shopware\Commands\ShopwareCommand;
use Shopware\Components\ConfigWriter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends ShopwareCommand
{
    const OPTION_REMOVE_TLS = 'remove-tls';
    const OPTION_NO_SECRET_REMOVE = 'no-secret-wipe';

    protected $conn;
    protected $dbConfig;
    protected $configWriter;

    /**
     * RunCommand constructor.
     * @param Connection $conn
     * @param $dbConfig
     * @param ConfigWriter $configWriter
     */
    public function __construct(Connection $conn, array $dbConfig, ConfigWriter $configWriter)
    {
        parent::__construct();
        $this->conn = $conn;
        $this->dbConfig = $dbConfig;
        $this->configWriter = $configWriter;
    }


    protected function configure()
    {
        $this->setName('prod2testing:run');

        $this->addOption(
            'config',
            'c',
            InputOption::VALUE_OPTIONAL,
            'Path to a configuriation json file, that should be used instead of the default config.'
        );
        $this->addOption(
            'additionalConfig',
            'a',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Path to a configuration json file, that should be added to the default config'
        );
        $this->addOption(
            self::OPTION_REMOVE_TLS,
            null,
            InputOption::VALUE_OPTIONAL,
            'You can disable tls, if you dev maschine is not created with tls support'
        );
        $this->addOption(
            self::OPTION_NO_SECRET_REMOVE,
            null,
            InputOption::VALUE_OPTIONAL,
            'Disable wiping of secrets (e.g. smtp, payment methods etc.)'
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /*
         * Start transaction
         */
        $output->writeln('<info>START TRANSACTION</info>');
        $output->writeln('');
        $this->conn->exec('START TRANSACTION');

        /*
         * Do tasks
         */
        $this->anonymizeData($input, $output);
        $this->clearSearchIndex($input, $output);
        $this->removeSecrets($input, $output);
        $this->removeTLSFromShops($input, $output);

        /*
         * Submit Changes
         */
        $output->writeln('');
        $output->writeln('<info>Commit changes</info>');
        $this->conn->exec('COMMIT');
        $output->writeln('');
        $output->writeln('<info>Success!</info>');

        return 0;
    }

    /**
     * @param $config
     * @return array[]
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function fetchSchema($config)
    {
        $allTables = [];
        foreach ($config as $tableName => $tableConfig) {
            $allTables[] = $tableName;
        }
        $stmt = $this->conn->executeQuery("
            SELECT
                TABLE_NAME,
                COLUMN_NAME,
                COLUMN_DEFAULT,
                IS_NULLABLE,
                DATA_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
            AND TABLE_NAME IN (?)
        ", [
            $this->dbConfig['dbname'],
            $allTables,
        ], [
            \PDO::PARAM_STR,
            Connection::PARAM_STR_ARRAY,
        ]);
        $result = [];

        // group schema entries by table name and index schema entries by column name
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
            $tableName = $row['TABLE_NAME'];
            $colName = $row['COLUMN_NAME'];
            if (isset($result[$tableName])) {
                $result[$tableName][$colName] = $row;
            } else {
                $result[$tableName] = [$colName => $row];
            }
        }
        return $result;
    }

    protected function anonymizeData(InputInterface $input, OutputInterface $output)
    {
        /*
         * Read base config
         */
        $configFile = __DIR__ . '/../config.json';
        $replaceConfigFile = $input->getOption('config');
        if ($replaceConfigFile) {
            $configFile = $replaceConfigFile;
        }
        if (!is_file($configFile)) {
            throw new \Exception("Anonymization configuration file does not exist.");
        }
        if (!is_readable($configFile)) {
            $output->writeln('<error>The given config file is not readable</error>');
            throw new \Exception("Anonymization configuration file is not readable");
        }

        $config = json_decode(file_get_contents($configFile));

        if (!$config) {
            $output->writeln('<error>The configuration contains invalid json</error>');
        }

        /*
         * Read additional config
         */
        foreach ($input->getOption('additionalConfig') as $additionalConfigFile) {
            if (!is_file($additionalConfigFile)) {
                throw new \Exception("The additional config '$additionalConfigFile' is not a file");
            }
            if (!is_readable($additionalConfigFile)) {
                throw new \Exception("The additional config '$additionalConfigFile' is not readable");
            }

            $additionalConfig = json_decode(file_get_contents($additionalConfigFile));
            if (!$additionalConfig) {
                throw new \Exception("The additional config '$additionalConfigFile' contains invalid json");
            }
            $config = array_replace_recursive($config, $additionalConfig);
        }

        // fetch schema information
        $informationSchema = $this->fetchSchema($config);

        // run anonymization
        foreach ($config as $tableName => $tableConfig) {

            if (!isset($informationSchema[$tableName])) {
                $output->writeln("<warning>The table '$tableName' is configured but does not exist in db. Continuing with next table.</warning>");
                continue;
            }

            $output->writeln("<info>Anonymize $tableName</info>");
            $keys = $this->conn->query("SHOW KEYS FROM `$tableName` WHERE Key_name = 'PRIMARY'")->fetchAll(\PDO::FETCH_ASSOC);
            $keyNames = array_map(function ($row) {
                return $row['Column_name'];
            }, $keys);
            $keyNamesString = '`' . implode('`, `', $keyNames) . '`';
            $columnNamesString = '`' . implode('`, `', array_keys((array)$tableConfig)) . '`';
            $x = 1;
            $stmt = $this->conn->query("SELECT $keyNamesString, $columnNamesString FROM `$tableName`");
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
                $qb = $this->conn->createQueryBuilder();
                $qb->update($tableName);

                $hasUpdate = false;
                foreach ($tableConfig as $columnName => $value) {
                    if (!isset($informationSchema[$tableName][$columnName])) {
                        $output->writeln("<warning>The column '$tableName.$columnName' does not exist in db. Continuing with next column.</warning>");
                        continue;
                    }

                    if (empty($row[$columnName])) {
                        continue;
                    }
                    $hasUpdate = true;
                    if (is_string($value)) {
                        $value = str_replace('{{x}}', $x, $value);
                    }
                    $param = ":{$columnName}_{$x}";
                    $qb->set($columnName, $param);
                    $qb->setParameter($param, $value);
                }
                if (!$hasUpdate) continue;

                foreach ($keyNames as $keyName) {
                    $qb->where(
                        $qb->expr()->eq($keyName, $row[$keyName])
                    );
                }
                $qb->execute();
                $x++;
            }
        }
    }

    /**
     * Truncate customer search index
     * Note: I used delete instead of truncate, because truncate does not respect fk etc. Delete is the saver method
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function clearSearchIndex(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("<info>Truncate customer search index</info>");
        $this->conn->exec("DELETE FROM s_customer_search_index");
    }

    /**
     * Wipes secrets from database (e.g. smtp password)
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function removeSecrets(InputInterface $input, OutputInterface $output)
    {
        $noSecretRemove = $input->getOption(self::OPTION_NO_SECRET_REMOVE);
        if ($noSecretRemove) return;

        $output->writeln("");
        $output->writeln('<info>Remove secrets</info>');
        $output->writeln("<info>\tSet mail method to php mail & Remove smtp password</info>");

        $this->configWriter->save('mailer_mailer', 'mail');
        $this->configWriter->save('mailer_password', '');
    }

    /**
     * Remove TLS flag from shops for enabling local development
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function removeTLSFromShops(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<info>Deactivate TLS for all shops</info>');
        $removeTLS = $input->getOption(self::OPTION_REMOVE_TLS);
        if ($removeTLS) $this->conn->exec("UPDATE s_core_shops SET secure = 0");
    }
}