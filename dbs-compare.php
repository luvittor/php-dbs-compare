<?php
// Carregar variáveis de ambiente do arquivo .env
require_once 'vendor/autoload.php';

use Dotenv\Dotenv;

// Carregar o .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configurações do Banco 1
$db1_host = @$_ENV['DB1_HOST'];
$db1_user = @$_ENV['DB1_USER'];
$db1_pass = @$_ENV['DB1_PASS'];
$db1_name = @$_ENV['DB1_NAME'];

// Configurações do Banco 2
$db2_host = @$_ENV['DB2_HOST'];
$db2_user = @$_ENV['DB2_USER'];
$db2_pass = @$_ENV['DB2_PASS'];
$db2_name = @$_ENV['DB2_NAME'];

// Configuração para contar registros das tabelas
$count_table_records = filter_var(@$_ENV['COUNT_TABLE_RECORDS'], FILTER_VALIDATE_BOOLEAN);

// Configuração para limitar a quantidade de tabelas que terão os registros contados para debug
$table_limit_debug = filter_var(@$_ENV['TABLE_LIMIT_DEBUG'], FILTER_VALIDATE_INT);

// Configurações iniciais
ini_set('max_execution_time', 0); // Sem limite de tempo para execução
date_default_timezone_set('America/Sao_Paulo'); // Definir fuso horário

// Caminhos para arquivos de saída
$output_dir = 'output';
if (!is_dir($output_dir)) mkdir($output_dir);

$log_file = "$output_dir/dbs-compare.log";
$files = [
    'tables' => "$output_dir/dbs-compare-tables.csv",
    'views' => "$output_dir/dbs-compare-views.csv",
    'triggers' => "$output_dir/dbs-compare-triggers.csv",
    'procedures' => "$output_dir/dbs-compare-procedures.csv",
    'functions' => "$output_dir/dbs-compare-functions.csv",
    'events' => "$output_dir/dbs-compare-events.csv",
    'foreign_keys' => "$output_dir/dbs-compare-foreign-keys.csv",
    'indexes' => "$output_dir/dbs-compare-indexes.csv",
    'configs' => "$output_dir/dbs-compare-configs.csv",
];

// Remover arquivos antigos
foreach ([$log_file] + $files as $file) {
    if (file_exists($file)) unlink($file);
}

// Função para registrar logs
function log_message($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($log_file, $line, FILE_APPEND);
    echo $line;
}

// Função para reconectar ao MySQL
function reconnect($host, $user, $pass, $db_name) {
    $conn = new mysqli($host, $user, $pass, $db_name);
    if ($conn->connect_error) {
        log_message("Falha na conexão com o banco: " . $conn->connect_error);
        exit;
    }
    return $conn;
}

// Função para comparar tabelas
function compareTables($conn1, $conn2, $db1_name, $db2_name, $count_records) {
    global $files, $table_limit_debug;

    $header = ["Tabela", "Banco 1", "Banco 2", "Registros Banco 1", "Registros Banco 2", "Diferença"];
    $handle = fopen($files['tables'], 'w');
    fputcsv($handle, $header, "\t");

    $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'";
    $stmt1 = $conn1->prepare($sql);
    $stmt1->bind_param('s', $db1_name);
    $stmt1->execute();
    $result1 = $stmt1->get_result();

    $stmt2 = $conn2->prepare($sql);
    $stmt2->bind_param('s', $db2_name);
    $stmt2->execute();
    $result2 = $stmt2->get_result();

    $tables_db1 = [];
    $tables_db2 = [];

    while ($row = $result1->fetch_assoc()) {
        $tables_db1[] = $row['table_name'];
    }
    while ($row = $result2->fetch_assoc()) {
        $tables_db2[] = $row['table_name'];
    }

    $all_tables = array_unique(array_merge($tables_db1, $tables_db2));

    if ($table_limit_debug) $all_tables = array_slice($all_tables, 0, $table_limit_debug);

    foreach ($all_tables as $table) {
        $exists1 = in_array($table, $tables_db1);
        $exists2 = in_array($table, $tables_db2);

        $count1 = "N/D";
        $count2 = "N/D";
        $diff = "N/D";

        // view

        if ($count_records) {
            if ($exists1) {
                $count_sql = "SELECT COUNT(*) as total FROM $table";
                $count_result = $conn1->query($count_sql);
                $count1 = $count_result ? $count_result->fetch_assoc()['total'] : "ERRO";
            }
            if ($exists2) {
                $count_sql = "SELECT COUNT(*) as total FROM $table";
                $count_result = $conn2->query($count_sql);
                $count2 = $count_result ? $count_result->fetch_assoc()['total'] : "ERRO";
            }

            $diff = is_numeric($count1) && is_numeric($count2) ? $count1 - $count2 : "N/D";
        }

        fputcsv($handle, [$table, $exists1 ? "EXISTE" : "NAO_EXISTE", $exists2 ? "EXISTE" : "NAO_EXISTE", $count1, $count2, $diff], "\t");
    }

    fclose($handle);
}

// Função para comparar outros objetos
function compareObjects($conn1, $conn2, $db1_name, $db2_name, $object_type, $sql, $file_key) {
    global $files;

    $header = [$object_type, "Banco 1", "Banco 2"];
    $handle = fopen($files[$file_key], 'w');
    fputcsv($handle, $header, "\t");

    $stmt1 = $conn1->prepare($sql);
    $stmt1->bind_param('s', $db1_name);
    $stmt1->execute();
    $result1 = $stmt1->get_result();

    $stmt2 = $conn2->prepare($sql);
    $stmt2->bind_param('s', $db2_name);
    $stmt2->execute();
    $result2 = $stmt2->get_result();

    $objects_db1 = [];
    $objects_db2 = [];

    while ($row = $result1->fetch_assoc()) {
        $objects_db1[] = $row[array_key_first($row)];
    }
    while ($row = $result2->fetch_assoc()) {
        $objects_db2[] = $row[array_key_first($row)];
    }

    $all_objects = array_unique(array_merge($objects_db1, $objects_db2));

    foreach ($all_objects as $object) {
        fputcsv($handle, [$object, in_array($object, $objects_db1) ? "EXISTE" : "NAO_EXISTE", in_array($object, $objects_db2) ? "EXISTE" : "NAO_EXISTE"], "\t");
    }

    fclose($handle);
}

// Função para comparar configurações
function compareConfigurations($conn1, $conn2) {
    global $files;

    $header = ["Configuração", "Valor Banco 1", "Valor Banco 2", "Status"];
    $handle = fopen($files['configs'], 'w');
    fputcsv($handle, $header, "\t");

    $sql = "SHOW VARIABLES";
    $result1 = $conn1->query($sql);
    $result2 = $conn2->query($sql);

    $configs_db1 = [];
    $configs_db2 = [];

    while ($row = $result1->fetch_assoc()) {
        $configs_db1[$row['Variable_name']] = $row['Value'];
    }
    while ($row = $result2->fetch_assoc()) {
        $configs_db2[$row['Variable_name']] = $row['Value'];
    }

    $all_configs = array_unique(array_merge(array_keys($configs_db1), array_keys($configs_db2)));

    foreach ($all_configs as $config) {
        $value1 = $configs_db1[$config] ?? "N/A";
        $value2 = $configs_db2[$config] ?? "N/A";
        $status = $value1 === $value2 ? "IGUAL" : "DIFERENTE";
        fputcsv($handle, [$config, $value1, $value2, $status], "\t");
    }

    fclose($handle);
}

// Reconectar aos bancos
$conn1 = reconnect($db1_host, $db1_user, $db1_pass, $db1_name);
$conn2 = reconnect($db2_host, $db2_user, $db2_pass, $db2_name);

// Comparar tabelas
log_message("Comparando tabelas...");
compareTables($conn1, $conn2, $db1_name, $db2_name, $count_table_records);

// Comparar outros objetos
$object_queries = [
    'views' => "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'VIEW'",
    'triggers' => "SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = ?",
    'procedures' => "SELECT routine_name FROM information_schema.routines WHERE routine_schema = ? AND routine_type = 'PROCEDURE'",
    'functions' => "SELECT routine_name FROM information_schema.routines WHERE routine_schema = ? AND routine_type = 'FUNCTION'",
    'events' => "SELECT event_name FROM information_schema.events WHERE event_schema = ?",
    'foreign_keys' => "SELECT constraint_name FROM information_schema.referential_constraints WHERE constraint_schema = ?",
    'indexes' => "SELECT index_name FROM information_schema.statistics WHERE table_schema = ?",
];

foreach ($object_queries as $key => $query) {
    log_message("Comparando $key...");
    compareObjects($conn1, $conn2, $db1_name, $db2_name, ucfirst($key), $query, $key);
}

// Comparar configurações
log_message("Comparando configurações...");
compareConfigurations($conn1, $conn2);

// Fechar conexões
$conn1->close();
$conn2->close();

log_message("Processo concluído!");
