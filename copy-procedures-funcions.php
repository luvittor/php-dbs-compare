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

// Função para conectar ao MySQL
function connect($host, $user, $pass, $db_name) {
    $conn = new mysqli($host, $user, $pass, $db_name);
    if ($conn->connect_error) {
        die("Erro na conexão: " . $conn->connect_error);
    }
    return $conn;
}

// Função para copiar stored functions
function copyFunctions($conn1, $conn2, $db1_name, $db2_name) {
    echo "Iniciando cópia de stored functions...\n";

    // Obter a lista de funções no banco 1
    $sql = "SELECT routine_name FROM information_schema.routines 
            WHERE routine_schema = '$db1_name' AND routine_type = 'FUNCTION'";
    $result = $conn1->query($sql);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $function_name = $row['routine_name'];

            // Obter a definição completa da função
            $show_create_result = $conn1->query("SHOW CREATE FUNCTION `$db1_name`.`$function_name`");
            if ($show_create_result && $show_create_result->num_rows > 0) {
                $row_create = $show_create_result->fetch_assoc();
                $create_function_sql = $row_create['Create Function'];

                //die($create_function_sql);

                // Ajustar a definição se necessário (por exemplo, substituir o definer)
                // $create_function_sql = str_replace('DEFINER=`usuario`@`host`', 'DEFINER=CURRENT_USER', $create_function_sql);

                // Remover função existente no banco 2 (se existir)
                $conn2->query("DROP FUNCTION IF EXISTS `$function_name`");

                // Criar a function no banco 2
                if ($conn2->multi_query($create_function_sql)) {
                    // Consumir todos os resultados para liberar a conexão
                    while ($conn2->more_results() && $conn2->next_result());
                    echo "Stored function `$function_name` copiada com sucesso.\n";
                } else {
                    echo "Erro ao copiar function `$function_name`: " . $conn2->error . "\n";
                }
            } else {
                echo "Erro ao obter definição da função `$function_name`: " . $conn1->error . "\n";
            }
        }
    } else {
        echo "Nenhuma stored function encontrada no banco 1.\n";
    }
}

function copyProcedures($conn1, $conn2, $db1_name, $db2_name) {
    echo "Iniciando cópia de stored procedures...\n";

    // Obter a lista de procedures no banco 1
    $sql = "SELECT routine_name FROM information_schema.routines 
            WHERE routine_schema = '$db1_name' AND routine_type = 'PROCEDURE'";
    $result = $conn1->query($sql);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $procedure_name = $row['routine_name'];

            // Obter a definição completa da procedure
            $show_create_result = $conn1->query("SHOW CREATE PROCEDURE `$db1_name`.`$procedure_name`");
            if ($show_create_result && $show_create_result->num_rows > 0) {
                $row_create = $show_create_result->fetch_assoc();
                $create_procedure_sql = $row_create['Create Procedure'];

                // Ajustar a definição se necessário
                // $create_procedure_sql = str_replace('DEFINER=`usuario`@`host`', 'DEFINER=CURRENT_USER', $create_procedure_sql);

                // Remover procedure existente no banco 2 (se existir)
                $conn2->query("DROP PROCEDURE IF EXISTS `$procedure_name`");

                // Criar a procedure no banco 2
                if ($conn2->multi_query($create_procedure_sql)) {
                    // Consumir todos os resultados para liberar a conexão
                    while ($conn2->more_results() && $conn2->next_result());
                    echo "Stored procedure `$procedure_name` copiada com sucesso.\n";
                } else {
                    echo "Erro ao copiar procedure `$procedure_name`: " . $conn2->error . "\n";
                }
            } else {
                echo "Erro ao obter definição da procedure `$procedure_name`: " . $conn1->error . "\n";
            }
        }
    } else {
        echo "Nenhuma stored procedure encontrada no banco 1.\n";
    }
}


// Conectar aos bancos
$conn1 = connect($db1_host, $db1_user, $db1_pass, $db1_name);
$conn2 = connect($db2_host, $db2_user, $db2_pass, $db2_name);

// Copiar functions
copyFunctions($conn1, $conn2, $db1_name, $db2_name);

// Copiar procedures
copyProcedures($conn1, $conn2, $db1_name, $db2_name);

// Fechar conexões
$conn1->close();
$conn2->close();
echo "Processo concluído.\n";
