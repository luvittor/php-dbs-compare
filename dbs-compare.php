<?php
// Carregar variáveis de ambiente do arquivo .env
require_once 'vendor/autoload.php';

use Dotenv\Dotenv;

// Carregar o .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Configurações do Banco 1
$db1_host = $_ENV['DB1_HOST'];
$db1_user = $_ENV['DB1_USER'];
$db1_pass = $_ENV['DB1_PASS'];
$db1_name = $_ENV['DB1_NAME'];

// Configurações do Banco 2
$db2_host = $_ENV['DB2_HOST'];
$db2_user = $_ENV['DB2_USER'];
$db2_pass = $_ENV['DB2_PASS'];
$db2_name = $_ENV['DB2_NAME'];

// Configurações iniciais
ini_set('max_execution_time', 0); // Sem limite de tempo para execução
date_default_timezone_set('America/Sao_Paulo'); // Definir fuso horário

// Caminhos para os arquivos de log e CSV
$log_file = 'dbs-compare.log';
$csv_file = 'dbs-compare.csv';

// Remover arquivos antigos, se existirem
if (file_exists($log_file)) unlink($log_file);
if (file_exists($csv_file)) unlink($csv_file);

// Função para registrar logs
function log_message($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($log_file, $line, FILE_APPEND);
    echo $line;
}

// Função para obter tabelas e contagem exata de registros
function getTableCounts($conn, $db_name, $db_label) {
    $tables = [];
    $sql = "SELECT table_name, table_type FROM information_schema.tables WHERE table_schema = '$db_name'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $table_name = $row['table_name'];
            $table_type = $row['table_type'];
            
            // Pular views
            if ($table_type === 'VIEW') {
                log_message("[$db_label] Ignorando view: $table_name");
                continue;
            }

            // Contar registros para tabelas
            $start_time = microtime(true); // Marcar tempo inicial
            log_message("[$db_label] Processando tabela: $table_name...");
            
            $count_sql = "SELECT COUNT(*) as total FROM $table_name";
            $count_result = $conn->query($count_sql);

            if ($count_result) {
                $count_row = $count_result->fetch_assoc();
                $tables[$table_name] = $count_row['total'];
            } else {
                $tables[$table_name] = "Erro: " . $conn->error;
            }

            $elapsed_time = round(microtime(true) - $start_time, 2); // Calcular tempo decorrido
            log_message("[$db_label] Concluído: $table_name em $elapsed_time segundos");

            // limitar a 10 tabelas para testes
            //if (count($tables) > 10) break;
        }
    }

    return $tables;
}

// Conexão ao Banco 1
$conn1 = new mysqli($db1_host, $db1_user, $db1_pass, $db1_name);
if ($conn1->connect_error) {
    log_message("Falha na conexão com o Banco 1: " . $conn1->connect_error);
    exit;
}

// Conexão ao Banco 2
$conn2 = new mysqli($db2_host, $db2_user, $db2_pass, $db2_name);
if ($conn2->connect_error) {
    log_message("Falha na conexão com o Banco 2: " . $conn2->connect_error);
    exit;
}

// Obtendo tabelas e contagem do Banco 1
log_message("Iniciando contagem de registros no Banco 1...");
$tables_db1 = getTableCounts($conn1, $db1_name, "Banco 1");

// Fechar conexão anterior para reconexão e evitar erros de timeout
$conn2->close();

// Reconexão ao Banco 2
$conn2 = new mysqli($db2_host, $db2_user, $db2_pass, $db2_name);
if ($conn2->connect_error) {
    log_message("Falha na conexão com o Banco 2: " . $conn2->connect_error);
    exit;
}

// Obtendo tabelas e contagem do Banco 2
log_message("Iniciando contagem de registros no Banco 2...");
$tables_db2 = getTableCounts($conn2, $db2_name, "Banco 2");

// Comparando tabelas
$all_tables = array_unique(array_merge(array_keys($tables_db1), array_keys($tables_db2)));
$statistics = [];

foreach ($all_tables as $table) {
    $count1 = isset($tables_db1[$table]) ? $tables_db1[$table] : "Não existe";
    $count2 = isset($tables_db2[$table]) ? $tables_db2[$table] : "Não existe";
    $statistics[] = [
        "Tabela" => $table,
        "Banco 1" => $count1,
        "Banco 2" => $count2,
        "Diferença" => (is_numeric($count1) && is_numeric($count2)) ? abs($count1 - $count2) : "N/A"
    ];
}

// Salvando o resultado em um arquivo CSV
$csv_header = ["Tabela", "Banco 1", "Banco 2", "Diferença"];
$csv_handle = fopen($csv_file, 'w');
fputcsv($csv_handle, $csv_header, "\t");

foreach ($statistics as $stat) {
    fputcsv($csv_handle, $stat, "\t");
}

fclose($csv_handle);
log_message("Resultados salvos no arquivo CSV: $csv_file");

// Fechando conexões
$conn1->close();
$conn2->close();

log_message("Processo concluído!");
