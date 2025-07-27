<?php
// Configurações iniciais e de depuração
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Inicia a sessão
session_start();

// Configuração de erros
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Conexão com o banco de dados compartilhado
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "farmacia";

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    createDatabaseAndTables($conn); // Cria tabela operadores e admin
} catch(PDOException $e) {
    error_log("Erro na conexão com o banco de dados: " . $e->getMessage());
    showAlert("Erro na conexão com o banco de dados: " . $e->getMessage());
    exit;
}
/**
 * Cria tabelas se não existirem
 */
function createDatabaseAndTables($conn, $operador_usuario = null, $is_admin = false) {
    try {
        // Cria tabela de operadores no banco farmacia
        $conn->exec("CREATE TABLE IF NOT EXISTS operadores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL UNIQUE,
            usuario VARCHAR(50) NOT NULL UNIQUE,
            senha VARCHAR(255) NOT NULL,
            nivel_acesso ENUM('operador', 'administrador') DEFAULT 'operador',
            table_suffix VARCHAR(100),
            data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Cria tabela de medicamentos do administrador
        $conn->exec("CREATE TABLE IF NOT EXISTS medicamentos_admin (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL UNIQUE,
            descricao TEXT,
            concentracao VARCHAR(50),
            apresentacao VARCHAR(50),
            quantidade INT NOT NULL DEFAULT 0,
            quantidade_minima INT NOT NULL DEFAULT 5,
            data_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Cria tabela de movimentações do administrador
        $conn->exec("CREATE TABLE IF NOT EXISTS movimentacoes_admin (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medicamento_id INT,
            tipo ENUM('entrada', 'saida') NOT NULL,
            quantidade INT NOT NULL,
            operador_id INT NOT NULL,
            operador_destino_id INT,
            data_movimentacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            observacao TEXT,
            FOREIGN KEY (medicamento_id) REFERENCES medicamentos_admin(id) ON DELETE CASCADE,
            FOREIGN KEY (operador_destino_id) REFERENCES operadores(id) ON DELETE SET NULL
        )");

        // Cria tabela de pedidos de medicamentos
        $conn->exec("CREATE TABLE IF NOT EXISTS pedidos_medicamentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medicamento_id INT NOT NULL,
            operador_id INT NOT NULL,
            quantidade_solicitada INT NOT NULL,
            observacao TEXT,
            status ENUM('pendente', 'aprovado', 'rejeitado') DEFAULT 'pendente',
            data_pedido TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (medicamento_id) REFERENCES medicamentos_admin(id) ON DELETE CASCADE,
            FOREIGN KEY (operador_id) REFERENCES operadores(id) ON DELETE CASCADE
        )");

        // Verifica se o admin padrão existe
        $stmt = $conn->query("SELECT COUNT(*) FROM operadores WHERE usuario = 'brenoportto'");
        if ($stmt->fetchColumn() == 0) {
            $senhaHash = password_hash('Sofia+123', PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO operadores (nome, usuario, senha, nivel_acesso, table_suffix)
                VALUES ('Administrador', 'brenoportto', ?, 'administrador', 'admin')");
            $stmt->execute([$senhaHash]);
        }

        // Se for um operador, cria tabelas específicas com o sufixo do usuario
        if ($operador_usuario && !$is_admin) {
            $table_suffix = str_replace([' ', '-', '.'], '_', strtolower($operador_usuario));

            // Tabela de medicamentos do operador
            $conn->exec("CREATE TABLE IF NOT EXISTS medicamentos_$table_suffix (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(100) NOT NULL,
                descricao TEXT,
                concentracao VARCHAR(50),
                apresentacao VARCHAR(50),
                quantidade INT NOT NULL DEFAULT 0,
                quantidade_minima INT NOT NULL DEFAULT 5,
                data_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (nome)
            )");

            // Tabela de movimentações do operador
            $conn->exec("CREATE TABLE IF NOT EXISTS movimentacoes_$table_suffix (
                id INT AUTO_INCREMENT PRIMARY KEY,
                medicamento_id INT,
                tipo ENUM('entrada', 'saida', 'pendente') NOT NULL,
                quantidade INT NOT NULL,
                operador_id INT NOT NULL,
                data_movimentacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                observacao TEXT,
                movimentacao_admin_id INT,
                FOREIGN KEY (medicamento_id) REFERENCES medicamentos_$table_suffix(id) ON DELETE CASCADE,
                FOREIGN KEY (movimentacao_admin_id) REFERENCES movimentacoes_admin(id) ON DELETE SET NULL
            )");

            // Sincroniza medicamentos do administrador com o operador
            $stmt = $conn->query("SELECT nome, descricao, concentracao, apresentacao, quantidade_minima
                FROM medicamentos_admin");
            $admin_medicamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($admin_medicamentos as $med) {
                $stmt = $conn->prepare("INSERT IGNORE INTO medicamentos_$table_suffix
                    (nome, descricao, concentracao, apresentacao, quantidade_minima)
                    VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $med['nome'],
                    $med['descricao'],
                    $med['concentracao'],
                    $med['apresentacao'],
                    $med['quantidade_minima']
                ]);
            }
        }
    } catch(PDOException $e) {
        showAlert("Erro ao criar tabelas: " . $e->getMessage());
        exit;
    }
}

// Funções auxiliares
function getApresentacaoOptions() {
    return [
        'Comprimido', 'Cápsula', 'Solução', 'Suspensão',
        'Injetável', 'Creme', 'Pomada', 'Xarope'
    ];
}

function getOperadores($conn) {
    try {
        $stmt = $conn->query("SELECT id, nome, usuario, nivel_acesso, data_cadastro, table_suffix
            FROM operadores
            ORDER BY nome");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        echo "<script>alert('Erro ao buscar operadores: " . addslashes($e->getMessage()) . "');</script>";
        return [];
    }
}

function showAlert($message, $resetForm = false, $redirect = null) {
    $script = "<script>alert('" . addslashes($message) . "');";
    if ($resetForm) {
        $script .= "document.getElementById('cadastroForm')?.reset();";
    }
    if ($redirect) {
        $script .= "window.location.href = '" . addslashes($redirect) . "';";
    }
    $script .= "</script>";
    echo $script;
}

// Função para gerar arquivo .xls
function generateXlsFile($filename, $title, $header_info, $headers, $data) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    echo '<h2>' . htmlspecialchars($title) . '</h2>';
    foreach ($header_info as $info) {
        echo '<p>' . htmlspecialchars($info) . '</p>';
    }
    echo '<table border="1">';
    echo '<thead><tr>';
    foreach ($headers as $header) {
        echo '<th style="font-weight: bold; background-color: #e8f5e9;">' . htmlspecialchars($header) . '</th>';
    }
    echo '</tr></thead>';
    echo '<tbody>';
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . htmlspecialchars($cell) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</body></html>';
    exit;
}

// Processamento do login
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    error_log("Tentativa de login iniciada");
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = trim($_POST['senha'] ?? '');
    
    // Validação básica
    if (empty($usuario) || empty($senha)) {
        $login_error = "Por favor, preencha todos os campos.";
        error_log("Tentativa de login com campos vazios");
    } elseif (strlen($usuario) > 50 || strlen($senha) > 255) {
        $login_error = "Dados de entrada muito longos.";
        error_log("Tentativa de login com dados muito longos");
    } else {
        try {
            $stmt = $conn->prepare("SELECT id, nome, usuario, senha, nivel_acesso, table_suffix
                FROM operadores
                WHERE usuario = ?");
            $stmt->execute([$usuario]);
            $operador = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($operador && password_verify($senha, $operador['senha'])) {
                error_log("Login bem-sucedido para o usuário: " . $usuario);
                // Regenera o ID da sessão para segurança
                session_regenerate_id(true);
                $_SESSION['operador_id'] = $operador['id'];
                $_SESSION['operador_nome'] = $operador['nome'];
                $_SESSION['operador_usuario'] = $operador['usuario'];
                $_SESSION['operador_nivel'] = $operador['nivel_acesso'];
                $_SESSION['table_suffix'] = $operador['table_suffix'];
                $_SESSION['login_time'] = time();
                $redirect_page = $_GET['redirect'] ?? 'cadastro';
                header("Location: index.php?page=$redirect_page");
                exit;
            } else {
                error_log("Falha no login para o usuário: " . $usuario);
                $login_error = "Usuário ou senha incorretos.";
            }
        } catch(PDOException $e) {
            error_log("Erro ao tentar fazer login: " . $e->getMessage());
            $login_error = "Erro ao tentar fazer login: " . $e->getMessage();
        }
    }
}
// Processamento do logout
if (isset($_GET['logout'])) {
    // Limpa todas as variáveis de sessão
    session_unset();
    session_destroy();
    
    // Define headers para impedir cache
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    // Redireciona para login com parâmetro de logout
    header("Location: index.php?logout=1");
    exit;
}

// Verifica se o usuário está logado
if (!isset($_SESSION['operador_id'])) {
    $page = 'login';
} else {
    // Verifica timeout da sessão (8 horas)
    $session_timeout = 8 * 60 * 60; // 8 horas em segundos
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $session_timeout) {
        session_unset();
        session_destroy();
        header("Location: index.php?timeout=1");
        exit;
    }
    
    // Verifica se a sessão é válida (tempo de login)
    if (!isset($_SESSION['login_time'])) {
        session_unset();
        session_destroy();
        header("Location: index.php?invalid=1");
        exit;
    }
    
    // Define headers para impedir cache em todas as páginas autenticadas
    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    $page = $_GET['page'] ?? 'cadastro';
}

// Processamento do cadastro de medicamentos (apenas administrador)
if ($page == 'cadastro' && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cadastrar'])) {
    if ($_SESSION['operador_nivel'] != 'administrador') {
        showAlert('Apenas administradores podem cadastrar novos medicamentos.');
    } else {
        $dados = [
            'nome' => trim($_POST['nome'] ?? ''),
            'descricao' => trim($_POST['descricao'] ?? ''),
            'concentracao' => trim($_POST['concentracao'] ?? ''),
            'apresentacao' => trim($_POST['apresentacao'] ?? ''),
            'quantidade' => (int)($_POST['quantidade'] ?? 0),
            'quantidade_minima' => (int)($_POST['quantidade_minima'] ?? 5),
            'operador_id' => (int)$_SESSION['operador_id']
        ];
        
        // Validação mais robusta
        if (empty($dados['nome'])) {
            showAlert('O nome do medicamento é obrigatório.');
        } elseif (strlen($dados['nome']) > 100) {
            showAlert('O nome do medicamento é muito longo (máximo 100 caracteres).');
        } elseif (strlen($dados['descricao']) > 65535) {
            showAlert('A descrição é muito longa.');
        } elseif (strlen($dados['concentracao']) > 50) {
            showAlert('A concentração é muito longa (máximo 50 caracteres).');
        } elseif ($dados['quantidade'] < 0 || $dados['quantidade_minima'] < 0) {
            showAlert('As quantidades devem ser números positivos.');
        } elseif ($dados['quantidade_minima'] > 999999) {
            showAlert('A quantidade mínima é muito alta.');
        } else {
            try {
                $conn->beginTransaction();
                $stmt = $conn->prepare("SELECT COUNT(*) FROM medicamentos_admin WHERE nome = ?");
                $stmt->execute([$dados['nome']]);
                if ($stmt->fetchColumn() > 0) {
                    showAlert('Um medicamento com este nome já existe. Considere registrar uma entrada na seção de Estoque.');
                } else {
                    // Insere o medicamento no estoque do administrador
                    $stmt = $conn->prepare("INSERT INTO medicamentos_admin (nome, descricao, concentracao, apresentacao, quantidade, quantidade_minima)
                        VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $dados['nome'], $dados['descricao'], $dados['concentracao'],
                        $dados['apresentacao'], $dados['quantidade'], $dados['quantidade_minima']
                    ]);
                    $medicamento_id = $conn->lastInsertId();

                    // Registra a entrada inicial se houver quantidade
                    if ($dados['quantidade'] > 0) {
                        $stmt = $conn->prepare("INSERT INTO movimentacoes_admin (medicamento_id, tipo, quantidade, operador_id)
                            VALUES (?, 'entrada', ?, ?)");
                        $stmt->execute([$medicamento_id, $dados['quantidade'], $dados['operador_id']]);
                    }

                    // Sincroniza com todos os operadores
                    $stmt = $conn->query("SELECT table_suffix FROM operadores WHERE table_suffix IS NOT NULL AND nivel_acesso = 'operador'");
                    $operators = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($operators as $op) {
                        $table_suffix = $op['table_suffix'];
                        $stmt = $conn->prepare("INSERT IGNORE INTO medicamentos_$table_suffix
                            (nome, descricao, concentracao, apresentacao, quantidade_minima)
                            VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $dados['nome'], $dados['descricao'], $dados['concentracao'],
                            $dados['apresentacao'], $dados['quantidade_minima']
                        ]);
                    }
                    $conn->commit();
                    showAlert('Medicamento cadastrado e sincronizado com sucesso!', true);
                }
            } catch(PDOException $e) {
                $conn->rollBack();
                showAlert('Erro ao cadastrar medicamento: ' . $e->getMessage());
            }
        }
    }
}

// Processamento de estoque (entradas, saídas, pedidos e confirmações)
if ($page == 'estoque') {
    $search_term = trim($_GET['busca'] ?? '');
    $medicamentos = [];
    $pendentes = [];
    $pedidos = [];

    if ($_SESSION['operador_nivel'] == 'administrador') {
        // Administrador vê o estoque principal
        $stmt = $conn->prepare("SELECT * FROM medicamentos_admin WHERE nome LIKE ? ORDER BY nome");
        $stmt->execute(["%$search_term%"]);
        $medicamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Busca pedidos pendentes
        $stmt = $conn->prepare("SELECT p.*, m.nome AS medicamento_nome, o.nome AS operador_nome
            FROM pedidos_medicamentos p
            JOIN medicamentos_admin m ON p.medicamento_id = m.id
            JOIN operadores o ON p.operador_id = o.id
            WHERE p.status = 'pendente'
            ORDER BY p.data_pedido DESC");
        $stmt->execute();
        $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Operador vê seu próprio estoque
        $stmt = $conn->prepare("SELECT * FROM medicamentos_{$_SESSION['table_suffix']} WHERE nome LIKE ? ORDER BY nome");
        $stmt->execute(["%$search_term%"]);
        $medicamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Busca movimentações pendentes
        $stmt = $conn->prepare("SELECT mov.*, m.nome AS medicamento_nome, o.nome AS operador_nome
            FROM movimentacoes_{$_SESSION['table_suffix']} mov
            JOIN medicamentos_{$_SESSION['table_suffix']} m ON mov.medicamento_id = m.id
            JOIN operadores o ON mov.operador_id = o.id
            WHERE mov.tipo = 'pendente'
            ORDER BY mov.data_movimentacao DESC");
        $stmt->execute();
        $pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Processa entrada (apenas administrador)
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['movimentar_entrada']) && $_SESSION['operador_nivel'] == 'administrador') {
        $dados = [
            'medicamento_id' => (int)($_POST['medicamento_id'] ?? 0),
            'quantidade_mov' => (int)($_POST['quantidade_mov'] ?? 0),
            'operador_id' => (int)$_SESSION['operador_id'],
            'observacao' => trim($_POST['observacao'] ?? '')
        ];
        if ($dados['quantidade_mov'] <= 0) {
            showAlert('Por favor, insira uma quantidade válida.');
        } else {
            try {
                $conn->beginTransaction();
                $conn->prepare("UPDATE medicamentos_admin SET quantidade = quantidade + ? WHERE id = ?")
                    ->execute([$dados['quantidade_mov'], $dados['medicamento_id']]);
                $conn->prepare("INSERT INTO movimentacoes_admin (medicamento_id, tipo, quantidade, operador_id, observacao)
                    VALUES (?, 'entrada', ?, ?, ?)")
                    ->execute([
                        $dados['medicamento_id'],
                        $dados['quantidade_mov'],
                        $dados['operador_id'],
                        $dados['observacao']
                    ]);
                $conn->commit();
                showAlert('Entrada registrada com sucesso!', false, 'index.php?page=estoque');
            } catch(PDOException $e) {
                $conn->rollBack();
                showAlert('Erro ao registrar entrada: ' . $e->getMessage());
            }
        }
    }

    // Processa saída (administrador para operador)
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['movimentar_saida']) && $_SESSION['operador_nivel'] == 'administrador') {
        $dados = [
            'medicamento_id' => (int)($_POST['medicamento_id'] ?? 0),
            'quantidade_mov' => (int)($_POST['quantidade_mov'] ?? 0),
            'operador_id' => (int)$_SESSION['operador_id'],
            'operador_destino_id' => (int)($_POST['operador_destino_id'] ?? 0),
            'observacao' => trim($_POST['observacao'] ?? '')
        ];
        if ($dados['quantidade_mov'] <= 0) {
            showAlert('Por favor, insira uma quantidade válida.');
        } elseif ($dados['operador_destino_id'] == 0) {
            showAlert('Por favor, selecione um operador de destino.');
        } else {
            try {
                $conn->beginTransaction();
                $stmt = $conn->prepare("SELECT quantidade FROM medicamentos_admin WHERE id = ? FOR UPDATE");
                $stmt->execute([$dados['medicamento_id']]);
                $current_quantity = $stmt->fetchColumn();
                if ($current_quantity === false) {
                    showAlert('Medicamento não encontrado.');
                    $conn->rollBack();
                } elseif ($current_quantity < $dados['quantidade_mov']) {
                    showAlert("Quantidade insuficiente no estoque! Quantidade atual: $current_quantity");
                    $conn->rollBack();
                } else {
                    // Atualiza o estoque do administrador
                    $conn->prepare("UPDATE medicamentos_admin SET quantidade = quantidade - ? WHERE id = ?")
                        ->execute([$dados['quantidade_mov'], $dados['medicamento_id']]);
                    // Registra a saída no administrador
                    $stmt = $conn->prepare("INSERT INTO movimentacoes_admin (medicamento_id, tipo, quantidade, operador_id, operador_destino_id, observacao)
                        VALUES (?, 'saida', ?, ?, ?, ?)");
                    $stmt->execute([
                        $dados['medicamento_id'],
                        $dados['quantidade_mov'],
                        $dados['operador_id'],
                        $dados['operador_destino_id'],
                        $dados['observacao']
                    ]);
                    $movimentacao_admin_id = $conn->lastInsertId();

                    // Obtém o table_suffix do operador destino
                    $stmt = $conn->prepare("SELECT table_suffix FROM operadores WHERE id = ?");
                    $stmt->execute([$dados['operador_destino_id']]);
                    $table_suffix = $stmt->fetchColumn();

                    // Obtém o ID do medicamento na tabela do operador
                    $stmt = $conn->prepare("SELECT id FROM medicamentos_$table_suffix WHERE nome = (SELECT nome FROM medicamentos_admin WHERE id = ?)");
                    $stmt->execute([$dados['medicamento_id']]);
                    $med_id_operador = $stmt->fetchColumn();

                    if ($med_id_operador === false) {
                        showAlert('Medicamento não encontrado no estoque do operador.');
                        $conn->rollBack();
                    } else {
                        // Registra a movimentação pendente no operador
                        $stmt = $conn->prepare("INSERT INTO movimentacoes_$table_suffix (medicamento_id, tipo, quantidade, operador_id, observacao, movimentacao_admin_id)
                            VALUES (?, 'pendente', ?, ?, ?, ?)");
                        $stmt->execute([
                            $med_id_operador,
                            $dados['quantidade_mov'],
                            $dados['operador_id'],
                            $dados['observacao'],
                            $movimentacao_admin_id
                        ]);
                        $conn->commit();
                        showAlert('Saída registrada e movimentação pendente criada para o operador!', false, 'index.php?page=estoque');
                    }
                }
            } catch(PDOException $e) {
                $conn->rollBack();
                showAlert('Erro ao registrar saída: ' . $e->getMessage());
            }
        }
    }

    // Processa pedido de medicamento (operador)
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['solicitar_medicamento']) && $_SESSION['operador_nivel'] == 'operador') {
        $dados = [
            'medicamento_id' => (int)($_POST['medicamento_id'] ?? 0),
            'quantidade_solicitada' => (int)($_POST['quantidade_solicitada'] ?? 0),
            'operador_id' => (int)$_SESSION['operador_id'],
            'observacao' => trim($_POST['observacao'] ?? '')
        ];
        if ($dados['quantidade_solicitada'] <= 0) {
            showAlert('Por favor, insira uma quantidade válida.');
        } else {
            try {
                $conn->beginTransaction();
                $stmt = $conn->prepare("INSERT INTO pedidos_medicamentos (medicamento_id, operador_id, quantidade_solicitada, observacao, status)
                    VALUES (?, ?, ?, ?, 'pendente')");
                $stmt->execute([
                    $dados['medicamento_id'],
                    $dados['operador_id'],
                    $dados['quantidade_solicitada'],
                    $dados['observacao']
                ]);
                $conn->commit();
                showAlert('Pedido de medicamento registrado com sucesso!', false, 'index.php?page=estoque');
            } catch(PDOException $e) {
                $conn->rollBack();
                showAlert('Erro ao registrar pedido: ' . $e->getMessage());
            }
        }
    }

    // Processa aprovação de pedido (administrador)
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['aprovar_pedido']) && $_SESSION['operador_nivel'] == 'administrador') {
        $pedido_id = (int)($_POST['pedido_id'] ?? 0);
        $quantidade_aprovada = (int)($_POST['quantidade_aprovada'] ?? 0);
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("SELECT medicamento_id, operador_id, quantidade_solicitada FROM pedidos_medicamentos WHERE id = ? AND status = 'pendente'");
            $stmt->execute([$pedido_id]);
            $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($pedido) {
                if ($quantidade_aprovada <= 0) {
                    showAlert('Por favor, insira uma quantidade válida.');
                    $conn->rollBack();
                } elseif ($quantidade_aprovada > $pedido['quantidade_solicitada']) {
                    showAlert('Quantidade aprovada não pode ser maior que a solicitada.');
                    $conn->rollBack();
                } else {
                    $stmt = $conn->prepare("SELECT quantidade FROM medicamentos_admin WHERE id = ? FOR UPDATE");
                    $stmt->execute([$pedido['medicamento_id']]);
                    $current_quantity = $stmt->fetchColumn();
                    if ($current_quantity === false) {
                        showAlert('Medicamento não encontrado.');
                        $conn->rollBack();
                    } elseif ($current_quantity < $quantidade_aprovada) {
                        showAlert("Quantidade insuficiente no estoque! Quantidade atual: $current_quantity");
                        $conn->rollBack();
                    } else {
                        // Atualiza o estoque do administrador
                        $conn->prepare("UPDATE medicamentos_admin SET quantidade = quantidade - ? WHERE id = ?")
                            ->execute([$quantidade_aprovada, $pedido['medicamento_id']]);
                        // Registra a saída no administrador
                        $stmt = $conn->prepare("INSERT INTO movimentacoes_admin (medicamento_id, tipo, quantidade, operador_id, operador_destino_id, observacao)
                            VALUES (?, 'saida', ?, ?, ?, ?)");
                        $stmt->execute([
                            $pedido['medicamento_id'],
                            $quantidade_aprovada,
                            $_SESSION['operador_id'],
                            $pedido['operador_id'],
                            'Aprovação de pedido #' . $pedido_id
                        ]);
                        $movimentacao_admin_id = $conn->lastInsertId();

                        // Obtém o table_suffix do operador
                        $stmt = $conn->prepare("SELECT table_suffix FROM operadores WHERE id = ?");
                        $stmt->execute([$pedido['operador_id']]);
                        $table_suffix = $stmt->fetchColumn();

                        // Obtém o ID do medicamento na tabela do operador
                        $stmt = $conn->prepare("SELECT id FROM medicamentos_$table_suffix WHERE nome = (SELECT nome FROM medicamentos_admin WHERE id = ?)");
                        $stmt->execute([$pedido['medicamento_id']]);
                        $med_id_operador = $stmt->fetchColumn();

                        if ($med_id_operador === false) {
                            showAlert('Medicamento não encontrado no estoque do operador.');
                            $conn->rollBack();
                        } else {
                            // Registra a movimentação pendente no operador
                            $stmt = $conn->prepare("INSERT INTO movimentacoes_$table_suffix (medicamento_id, tipo, quantidade, operador_id, observacao, movimentacao_admin_id)
                                VALUES (?, 'pendente', ?, ?, ?, ?)");
                            $stmt->execute([
                                $med_id_operador,
                                $quantidade_aprovada,
                                $_SESSION['operador_id'],
                                'Aprovação de pedido #' . $pedido_id,
                                $movimentacao_admin_id
                            ]);
                            // Atualiza o status do pedido
                            $conn->prepare("UPDATE pedidos_medicamentos SET status = 'aprovado' WHERE id = ?")
                                ->execute([$pedido_id]);
                            $conn->commit();
                            showAlert('Pedido aprovado e movimentação registrada com sucesso!', false, 'index.php?page=estoque');
                        }
                    }
                }
            } else {
                showAlert('Pedido não encontrado ou já processado.');
                $conn->rollBack();
            }
        } catch(PDOException $e) {
            $conn->rollBack();
            showAlert('Erro ao aprovar pedido: ' . $e->getMessage());
        }
    }

    // Processa rejeição de pedido (administrador)
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['rejeitar_pedido']) && $_SESSION['operador_nivel'] == 'administrador') {
        $pedido_id = (int)($_POST['pedido_id'] ?? 0);
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("UPDATE pedidos_medicamentos SET status = 'rejeitado' WHERE id = ? AND status = 'pendente'");
            $stmt->execute([$pedido_id]);
            if ($stmt->rowCount() > 0) {
                $conn->commit();
                showAlert('Pedido rejeitado com sucesso!', false, 'index.php?page=estoque');
            } else {
                $conn->rollBack();
                showAlert('Pedido não encontrado ou já processado.');
            }
        } catch(PDOException $e) {
            $conn->rollBack();
            showAlert('Erro ao rejeitar pedido: ' . $e->getMessage());
        }
    }

    // Confirma entrada pendente (operador)
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['confirmar_entrada']) && $_SESSION['operador_nivel'] == 'operador') {
        $movimentacao_id = (int)($_POST['movimentacao_id'] ?? 0);
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("SELECT medicamento_id, quantidade, observacao FROM movimentacoes_{$_SESSION['table_suffix']}
                WHERE id = ? AND tipo = 'pendente'");
            $stmt->execute([$movimentacao_id]);
            $movimentacao = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($movimentacao) {
                // Atualiza o estoque do operador
                $conn->prepare("UPDATE medicamentos_{$_SESSION['table_suffix']} SET quantidade = quantidade + ?
                    WHERE id = ?")
                    ->execute([$movimentacao['quantidade'], $movimentacao['medicamento_id']]);
                // Atualiza a movimentação para entrada
                $conn->prepare("UPDATE movimentacoes_{$_SESSION['table_suffix']}
                    SET tipo = 'entrada', data_movimentacao = CURRENT_TIMESTAMP
                    WHERE id = ?")
                    ->execute([$movimentacao_id]);
                $conn->commit();
                showAlert('Entrada confirmada com sucesso!', false, 'index.php?page=estoque');
            } else {
                $conn->rollBack();
                showAlert('Movimentação pendente não encontrada.');
            }
        } catch(PDOException $e) {
            $conn->rollBack();
            showAlert('Erro ao confirmar entrada: ' . $e->getMessage());
        }
    }

    // Processa saída (operador)
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['movimentar_saida']) && $_SESSION['operador_nivel'] == 'operador') {
        $dados = [
            'medicamento_id' => (int)($_POST['medicamento_id'] ?? 0),
            'quantidade_mov' => (int)($_POST['quantidade_mov'] ?? 0),
            'operador_id' => (int)$_SESSION['operador_id'],
            'observacao' => trim($_POST['observacao'] ?? ''),
            'table_suffix' => $_SESSION['table_suffix']
        ];
        if ($dados['quantidade_mov'] <= 0) {
            showAlert('Por favor, insira uma quantidade válida.');
        } else {
            try {
                $conn->beginTransaction();
                $stmt = $conn->prepare("SELECT quantidade FROM medicamentos_{$dados['table_suffix']} WHERE id = ? FOR UPDATE");
                $stmt->execute([$dados['medicamento_id']]);
                $current_quantity = $stmt->fetchColumn();
                if ($current_quantity === false) {
                    showAlert('Medicamento não encontrado.');
                    $conn->rollBack();
                } elseif ($current_quantity < $dados['quantidade_mov']) {
                    showAlert("Quantidade insuficiente no estoque! Quantidade atual: $current_quantity");
                    $conn->rollBack();
                } else {
                    $conn->prepare("UPDATE medicamentos_{$dados['table_suffix']} SET quantidade = quantidade - ? WHERE id = ?")
                        ->execute([$dados['quantidade_mov'], $dados['medicamento_id']]);
                    $conn->prepare("INSERT INTO movimentacoes_{$dados['table_suffix']} (medicamento_id, tipo, quantidade, operador_id, observacao)
                        VALUES (?, 'saida', ?, ?, ?)")
                        ->execute([
                            $dados['medicamento_id'],
                            $dados['quantidade_mov'],
                            $dados['operador_id'],
                            $dados['observacao']
                        ]);
                    $conn->commit();
                    showAlert('Saída registrada com sucesso!', false, 'index.php?page=estoque');
                }
            } catch(PDOException $e) {
                $conn->rollBack();
                showAlert('Erro ao registrar saída: ' . $e->getMessage());
            }
        }
    }

    // Exportar pedidos pendentes para .xls (administrador)
    if ($_SESSION['operador_nivel'] == 'administrador' && isset($_GET['export_pedidos_xls'])) {
        $filename = 'relatorio_pedidos_' . date('Ymd_His');
        $title = 'Relatório de Pedidos Pendentes';
        $header_info = ['Data: ' . date('d/m/Y H:i')];
        $headers = ['ID', 'Medicamento', 'Operador', 'Quantidade Solicitada', 'Observação', 'Data do Pedido'];
        $data = [];
        foreach ($pedidos as $pedido) {
            $data[] = [
                $pedido['id'],
                $pedido['medicamento_nome'],
                $pedido['operador_nome'],
                $pedido['quantidade_solicitada'],
                $pedido['observacao'],
                date('d/m/Y H:i', strtotime($pedido['data_pedido']))
            ];
        }
        generateXlsFile($filename, $title, $header_info, $headers, $data);
    }
}

// Processamento de relatórios
if ($page == 'relatorio') {
    $report_search_term = trim($_GET['busca_relatorio'] ?? '');
    $start_date = trim($_GET['data_inicio'] ?? '');
    $end_date = trim($_GET['data_fim'] ?? '');
    $report_operator_id = isset($_GET['operador_relatorio']) ? (int)$_GET['operador_relatorio'] : null;
    $report_type = $_GET['tipo_relatorio'] ?? 'movimentacoes';
    $movimentacao_tipo = $_GET['movimentacao_tipo'] ?? '';

    if ($report_type == 'movimentacoes') {
        $movimentacoes = [];
        if ($_SESSION['operador_nivel'] == 'administrador') {
            $sql = "SELECT m.nome AS medicamento_nome, m.quantidade AS estoque_atual, mov.*, o.nome AS operador_nome
                FROM movimentacoes_admin mov
                JOIN medicamentos_admin m ON mov.medicamento_id = m.id
                JOIN operadores o ON mov.operador_id = o.id
                WHERE m.nome LIKE ?";
            $params = ["%$report_search_term%"];
            if (!empty($movimentacao_tipo)) {
                $sql .= " AND mov.tipo = ?";
                $params[] = $movimentacao_tipo;
            }
            if (!empty($start_date)) {
                $sql .= " AND DATE(mov.data_movimentacao) >= ?";
                $params[] = $start_date;
            }
            if (!empty($end_date)) {
                $sql .= " AND DATE(mov.data_movimentacao) <= ?";
                $params[] = $end_date;
            }
            if (!empty($report_operator_id)) {
                $sql .= " AND mov.operador_id = ?";
                $params[] = $report_operator_id;
            }
            $sql .= " ORDER BY mov.data_movimentacao DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $movimentacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $sql = "SELECT m.nome AS medicamento_nome, m.quantidade AS estoque_atual, mov.*, o.nome AS operador_nome
                FROM movimentacoes_{$_SESSION['table_suffix']} mov
                JOIN medicamentos_{$_SESSION['table_suffix']} m ON mov.medicamento_id = m.id
                JOIN operadores o ON mov.operador_id = o.id
                WHERE m.nome LIKE ?";
            $params = ["%$report_search_term%"];
            if (!empty($movimentacao_tipo)) {
                $sql .= " AND mov.tipo = ?";
                $params[] = $movimentacao_tipo;
            }
            if (!empty($start_date)) {
                $sql .= " AND DATE(mov.data_movimentacao) >= ?";
                $params[] = $start_date;
            }
            if (!empty($end_date)) {
                $sql .= " AND DATE(mov.data_movimentacao) <= ?";
                $params[] = $end_date;
            }
            if (!empty($report_operator_id)) {
                $sql .= " AND mov.operador_id = ?";
                $params[] = $report_operator_id;
            }
            $sql .= " ORDER BY mov.data_movimentacao DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $movimentacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Exportar para .xls
        if (isset($_GET['export_xls'])) {
            $filename = 'relatorio_movimentacoes_' . date('Ymd_His');
            $title = 'Relatório de Movimentações';
            $header_info = [
                'Período: ' . (!empty($start_date) ? $start_date : 'Início') . ' a ' . (!empty($end_date) ? $end_date : 'Fim')
            ];
            $headers = ['Data', 'Medicamento', 'Tipo', 'Quantidade', 'Estoque Final', 'Operador', 'Observação'];
            $data = [];
            foreach ($movimentacoes as $mov) {
                $data[] = [
                    date('d/m/Y H:i', strtotime($mov['data_movimentacao'])),
                    $mov['medicamento_nome'],
                    ucfirst($mov['tipo']),
                    $mov['quantidade'],
                    $mov['estoque_atual'],
                    $mov['operador_nome'],
                    $mov['observacao']
                ];
            }
            generateXlsFile($filename, $title, $header_info, $headers, $data);
        }
    } else {
        $estoque = [];
        if ($_SESSION['operador_nivel'] == 'administrador') {
            $stmt = $conn->prepare("SELECT * FROM medicamentos_admin WHERE nome LIKE ? ORDER BY quantidade ASC, nome");
            $stmt->execute(["%$report_search_term%"]);
            $estoque = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $conn->prepare("SELECT * FROM medicamentos_{$_SESSION['table_suffix']} WHERE nome LIKE ? ORDER BY quantidade ASC, nome");
            $stmt->execute(["%$report_search_term%"]);
            $estoque = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Exportar para .xls
        if (isset($_GET['export_xls'])) {
            $filename = 'relatorio_estoque_' . date('Ymd_His');
            $title = 'Relatório de Estoque';
            $header_info = ['Data: ' . date('d/m/Y H:i')];
            $headers = ['ID', 'Medicamento', 'Descrição', 'Concentração', 'Apresentação', 'Estoque Atual', 'Qtd. Mínima', 'Status'];
            $data = [];
            foreach ($estoque as $med) {
                $status = '';
                if ($med['quantidade'] == 0) {
                    $status = 'ESGOTADO';
                } elseif ($med['quantidade'] <= $med['quantidade_minima']) {
                    $status = 'BAIXO ESTOQUE';
                } else {
                    $status = 'OK';
                }
                $data[] = [
                    $med['id'],
                    $med['nome'],
                    $med['descricao'],
                    $med['concentracao'],
                    $med['apresentacao'],
                    $med['quantidade'],
                    $med['quantidade_minima'],
                    $status
                ];
            }
            generateXlsFile($filename, $title, $header_info, $headers, $data);
        }
    }
}

// Processamento de operadores (apenas para administradores)
if ($page == 'operadores' && $_SESSION['operador_nivel'] == 'administrador') {
    // Cadastro de novo operador
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cadastrar_operador'])) {
        $dados = [
            'nome' => trim($_POST['nome'] ?? ''),
            'usuario' => trim($_POST['usuario'] ?? ''),
            'senha' => trim($_POST['senha'] ?? ''),
            'confirmar_senha' => trim($_POST['confirmar_senha'] ?? ''),
            'nivel_acesso' => $_POST['nivel_acesso'] ?? 'operador'
        ];
        if (empty($dados['nome']) || empty($dados['usuario']) || empty($dados['senha'])) {
            showAlert('Por favor, preencha todos os campos obrigatórios.');
        } elseif ($dados['senha'] !== $dados['confirmar_senha']) {
            showAlert('As senhas não coincidem.');
        } else {
            try {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM operadores WHERE usuario = ?");
                $stmt->execute([$dados['usuario']]);
                if ($stmt->fetchColumn() > 0) {
                    showAlert('Já existe um operador com este nome de usuário.');
                } else {
                    $conn->beginTransaction();
                    $senhaHash = password_hash($dados['senha'], PASSWORD_DEFAULT);
                    $table_suffix = ($dados['nivel_acesso'] == 'operador') ? str_replace([' ', '-', '.'], '_', strtolower($dados['usuario'])) : null;
                    $stmt = $conn->prepare("INSERT INTO operadores (nome, usuario, senha, nivel_acesso, table_suffix)
                        VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $dados['nome'],
                        $dados['usuario'],
                        $senhaHash,
                        $dados['nivel_acesso'],
                        $table_suffix
                    ]);
                    if ($dados['nivel_acesso'] == 'operador') {
                        createDatabaseAndTables($conn, $dados['usuario'], false);
                    }
                    $conn->commit();
                    showAlert('Operador cadastrado com sucesso!', false, 'index.php?page=operadores');
                }
            } catch(PDOException $e) {
                $conn->rollBack();
                showAlert('Erro ao cadastrar operador: ' . $e->getMessage());
            }
        }
    }

    // Exclusão de operador
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['excluir_operador'])) {
        $operador_id = (int)($_POST['operador_id'] ?? 0);
        if ($_SESSION['operador_id'] == $operador_id) {
            showAlert('Não é possível excluir o operador que está em uso na sessão atual.');
        } else {
            try {
                $stmt = $conn->prepare("SELECT table_suffix FROM operadores WHERE id = ?");
                $stmt->execute([$operador_id]);
                $table_suffix = $stmt->fetchColumn();
                if ($table_suffix) {
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM movimentacoes_$table_suffix");
                    $stmt->execute();
                    if ($stmt->fetchColumn() > 0) {
                        showAlert('Não é possível excluir este operador, pois ele possui movimentações associadas.');
                    } else {
                        $conn->exec("DROP TABLE IF EXISTS medicamentos_$table_suffix");
                        $conn->exec("DROP TABLE IF EXISTS movimentacoes_$table_suffix");
                        $conn->prepare("DELETE FROM operadores WHERE id = ?")
                            ->execute([$operador_id]);
                        showAlert('Operador excluído com sucesso!', false, 'index.php?page=operadores');
                    }
                } else {
                    $conn->prepare("DELETE FROM operadores WHERE id = ?")
                        ->execute([$operador_id]);
                    showAlert('Operador excluído com sucesso!', false, 'index.php?page=operadores');
                }
            } catch(PDOException $e) {
                showAlert('Erro ao excluir operador: ' . $e->getMessage());
            }
        }
    }

    // Redefinição de senha
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['resetar_senha'])) {
        $dados = [
            'operador_id' => (int)($_POST['operador_id'] ?? 0),
            'nova_senha' => trim($_POST['nova_senha'] ?? ''),
            'confirmar_senha' => trim($_POST['confirmar_senha'] ?? '')
        ];
        if (empty($dados['nova_senha'])) {
            showAlert('Por favor, insira uma nova senha.');
        } elseif ($dados['nova_senha'] !== $dados['confirmar_senha']) {
            showAlert('As senhas não coincidem.');
        } else {
            try {
                $senhaHash = password_hash($dados['nova_senha'], PASSWORD_DEFAULT);
                $conn->prepare("UPDATE operadores SET senha = ? WHERE id = ?")
                    ->execute([$senhaHash, $dados['operador_id']]);
                showAlert('Senha redefinida com sucesso!', false, 'index.php?page=operadores');
            } catch(PDOException $e) {
                showAlert('Erro ao redefinir senha: ' . $e->getMessage());
            }
        }
    }

    $operadores = getOperadores($conn);
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Sistema de Gestão - CAF</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <?php if ($page == 'login'): ?>
        <div class="login-container">
            <div class="login-header">
                <img src="logo.png" alt="Logo CAF" class="logo">
                <h2>Sistema de Gestão</h2>
                <p class="login-subtitle">Faça login para acessar o sistema</p>
            </div>
            
            <?php if (isset($_GET['timeout'])): ?>
                <div class="error-message">
                    <span class="error-icon">⚠️</span>
                    Sessão expirada. Por favor, faça login novamente.
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['invalid'])): ?>
                <div class="error-message">
                    <span class="error-icon">⚠️</span>
                    Sessão inválida. Por favor, faça login novamente.
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['logout'])): ?>
                <div class="alert">
                    <span class="success-icon">✅</span>
                    Logout realizado com sucesso. Faça login para continuar.
                </div>
            <?php endif; ?>
            <?php if (isset($login_error)): ?>
                <div class="error-message">
                    <span class="error-icon">❌</span>
                    <?php echo htmlspecialchars($login_error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="index.php" class="login-form">
                <div class="form-group">
                    <label for="usuario">
                        <span class="input-icon">👤</span>
                        Usuário
                    </label>
                    <input type="text" id="usuario" name="usuario" required placeholder="Usuário">
                </div>
                <div class="form-group">
                    <label for="senha">
                        <span class="input-icon">🔒</span>
                        Senha
                    </label>
                    <input type="password" id="senha" name="senha" required placeholder="Senha">
                </div>
                <button type="submit" name="login">
                    <span class="button-icon">🚀</span>
                    <span class="button-text">Entrar no Sistema</span>
                    <span class="loading-spinner" style="display: none;">⏳</span>
                </button>
            </form>
<?php if (isset($login_error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>
            <div class="login-footer">
                <p>© 2024 Sistema de Gestão CAF</p>
            </div>
        </div>
    <?php else: ?>
        <div class="container">
            <img src="logo.png" alt="Logo CAF" class="logo">
            <h1>Sistema de Gestão - CAF</h1>
            <!-- Optional Hamburger Menu (Uncomment to enable) -->
            <!-- <div class="hamburger" onclick="toggleNav()">&#9776;</div> -->
            <nav>
                <div class="nav-links">
                    <a href="?page=estoque" class="<?php echo $page == 'estoque' ? 'active' : ''; ?>">Estoque</a>
                    <?php if ($_SESSION['operador_nivel'] == 'administrador'): ?>
                        <a href="?page=cadastro" class="<?php echo $page == 'cadastro' ? 'active' : ''; ?>">Cadastro</a>
                    <?php endif; ?>
                    <a href="?page=relatorio" class="<?php echo $page == 'relatorio' ? 'active' : ''; ?>">Relatório</a>
                    <?php if ($_SESSION['operador_nivel'] == 'administrador'): ?>
                        <a href="?page=operadores" class="<?php echo $page == 'operadores' ? 'active' : ''; ?>">Operadores</a>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    Logado como: <strong><?php echo htmlspecialchars($_SESSION['operador_nome']); ?></strong>
                    (<em><?php echo htmlspecialchars($_SESSION['operador_nivel']); ?></em>) |
                    <a href="javascript:void(0)" onclick="secureLogout()">Sair</a>
                </div>
            </nav>
            <div class="content">
                <?php if ($page == 'cadastro' && $_SESSION['operador_nivel'] == 'administrador'): ?>
                    <h2>Cadastrar Novo Medicamento</h2>
                    <form method="POST" id="cadastroForm" action="index.php?page=cadastro">
                        <div class="form-group">
                            <label for="nome">Nome do Medicamento</label>
                            <input type="text" id="nome" name="nome" required>
                        </div>
                        <div class="form-group">
                            <label for="descricao">Descrição</label>
                            <textarea id="descricao" name="descricao" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="concentracao">Concentração (Ex: 500mg)</label>
                            <input type="text" id="concentracao" name="concentracao">
                        </div>
                        <div class="form-group">
                            <label for="apresentacao">Apresentação</label>
                            <select id="apresentacao" name="apresentacao">
                                <option value="">Selecione...</option>
                                <?php foreach (getApresentacaoOptions() as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="quantidade">Quantidade Inicial (Estoque)</label>
                            <input type="number" id="quantidade" name="quantidade" min="0" value="0" required>
                        </div>
                        <div class="form-group">
                            <label for="quantidade_minima">Quantidade Mínima (Alerta)</label>
                            <input type="number" id="quantidade_minima" name="quantidade_minima" min="0" value="5" required>
                        </div>
                        <button type="submit" name="cadastrar">Cadastrar Medicamento</button>
                    </form>
                <?php elseif ($page == 'estoque'): ?>
                    <h2>Gerenciar Estoque</h2>
                    <form method="GET" class="search-form">
                        <input type="hidden" name="page" value="estoque">
                        <div class="form-group">
                            <label for="busca">Buscar Medicamento</label>
                            <input type="text" id="busca" name="busca" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="Digite o nome do medicamento...">
                        </div>
                        <button type="submit">Buscar</button>
                    </form>
                    <table role="grid" aria-labelledby="estoque-caption">
                        <caption id="estoque-caption" class="hidden">Lista de Medicamentos no Estoque</caption>
                        <thead>
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Nome</th>
                                <th scope="col">Descrição</th>
                                <th scope="col">Concentração</th>
                                <th scope="col">Apresentação</th>
                                <th scope="col">Estoque Atual</th>
                                <th scope="col">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($medicamentos)): ?>
                                <tr><td colspan="7">Nenhum medicamento encontrado.</td></tr>
                            <?php else: ?>
                                <?php foreach ($medicamentos as $med):
                                    $class = '';
                                    if ($med['quantidade'] <= $med['quantidade_minima']) {
                                        $class = $med['quantidade'] == 0 ? 'critical-stock' : 'low-stock';
                                    }
                                ?>
                                    <tr class="<?php echo $class; ?>">
                                        <td data-label="ID"><?php echo htmlspecialchars($med['id']); ?></td>
                                        <td data-label="Nome"><?php echo htmlspecialchars($med['nome']); ?></td>
                                        <td data-label="Descrição"><?php echo htmlspecialchars($med['descricao']); ?></td>
                                        <td data-label="Concentração"><?php echo htmlspecialchars($med['concentracao']); ?></td>
                                        <td data-label="Apresentação"><?php echo htmlspecialchars($med['apresentacao']); ?></td>
                                        <td data-label="Estoque Atual"><?php echo htmlspecialchars($med['quantidade']); ?></td>
                                        <td data-label="Ação">
                                            <?php if ($_SESSION['operador_nivel'] == 'administrador'): ?>
                                                <form method="POST" class="inline-form" action="index.php?page=estoque">
                                                    <input type="hidden" name="medicamento_id" value="<?php echo htmlspecialchars($med['id']); ?>">
                                                    <input type="number" name="quantidade_mov" min="1" placeholder="Qtd." required>
                                                    <input type="text" name="observacao" placeholder="Observação (opcional)">
                                                    <select name="operador_destino_id">
                                                        <option value="">Selecione Operador</option>
                                                        <?php foreach (getOperadores($conn) as $op): ?>
                                                            <?php if ($op['nivel_acesso'] == 'operador'): ?>
                                                                <option value="<?php echo $op['id']; ?>"><?php echo htmlspecialchars($op['nome']); ?></option>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" name="movimentar_entrada">Entrada</button>
                                                    <button type="submit" name="movimentar_saida" <?php echo $med['quantidade'] == 0 ? 'disabled' : ''; ?>>Saída</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" class="inline-form" action="index.php?page=estoque">
                                                    <input type="hidden" name="medicamento_id" value="<?php echo htmlspecialchars($med['id']); ?>">
                                                    <input type="number" name="quantidade_solicitada" min="1" placeholder="Qtd." required>
                                                    <input type="text" name="observacao" placeholder="Observação (opcional)">
                                                    <button type="submit" name="solicitar_medicamento">Solicitar</button>
                                                    <button type="submit" name="movimentar_saida" <?php echo $med['quantidade'] == 0 ? 'disabled' : ''; ?>>Saída</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
<?php if ($_SESSION['operador_nivel'] == 'operador' && !empty($pendentes)): ?>
    <h2>Movimentações Pendentes</h2>
    <table role="grid" aria-labelledby="pendentes-caption">
        <caption id="pendentes-caption" class="hidden">Lista de Movimentações Pendentes</caption>
        <thead>
            <tr>
                                    <th scope="col">Data</th>
                                    <th scope="col">Medicamento</th>
                                    <th scope="col">Quantidade</th>
                                    <th scope="col">Operador</th>
                                    <th scope="col">Observação</th>
                                    <th scope="col">Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendentes as $mov): ?>
                                    <tr>
                                        <td data-label="Data"><?php echo date('d/m/Y H:i', strtotime($mov['data_movimentacao'])); ?></td>
                                        <td data-label="Medicamento"><?php echo htmlspecialchars($mov['medicamento_nome']); ?></td>
                                        <td data-label="Quantidade"><?php echo htmlspecialchars($mov['quantidade']); ?></td>
                                        <td data-label="Operador"><?php echo htmlspecialchars($mov['operador_nome']); ?></td>
                                        <td data-label="Observação"><?php echo htmlspecialchars($mov['observacao']); ?></td>
                                        <td data-label="Ação">
                                            <form method="POST" class="inline-form" action="index.php?page=estoque">
                                                <input type="hidden" name="movimentacao_id" value="<?php echo htmlspecialchars($mov['id']); ?>">
                                                <button type="submit" name="confirmar_entrada">Confirmar Entrada</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if ($_SESSION['operador_nivel'] == 'administrador' && !empty($pedidos)): ?>
                        <h2>Pedidos Pendentes</h2>
                        <div class="export-buttons">
                            <a href="?page=estoque&export_pedidos_xls=1" class="button">Exportar Pedidos para Excel</a>
                            <button onclick="window.print()" class="button">Imprimir Pedidos</button>
                        </div>
                        <table role="grid" aria-labelledby="pedidos-caption">
                            <caption id="pedidos-caption" class="hidden">Lista de Pedidos Pendentes</caption>
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Medicamento</th>
                                    <th scope="col">Operador</th>
                                    <th scope="col">Quantidade Solicitada</th>
                                    <th scope="col">Observação</th>
                                    <th scope="col">Data do Pedido</th>
                                    <th scope="col">Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pedidos as $pedido): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pedido['id']); ?></td>
                                    <td><?php echo htmlspecialchars($pedido['medicamento_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($pedido['operador_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($pedido['quantidade_solicitada']); ?></td>
                                    <td><?php echo htmlspecialchars($pedido['observacao']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($pedido['data_pedido'])); ?></td>
                                    <td>
                                        <form method="POST" class="inline-form" action="index.php?page=estoque">
                                            <input type="hidden" name="pedido_id" value="<?php echo htmlspecialchars($pedido['id']); ?>">
                                            <input type="number" name="quantidade_aprovada" min="1" max="<?php echo htmlspecialchars($pedido['quantidade_solicitada']); ?>" placeholder="Qtd. Aprovada" required>
                                            <button type="submit" name="aprovar_pedido">Aprovar</button>
                                            <button type="submit" name="rejeitar_pedido" class="delete">Rejeitar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php elseif ($page == 'relatorio'): ?>
                <h2>Relatórios</h2>
                <div class="tab-container">
                    <div class="tab-links">
                        <a href="#" class="active" onclick="openTab(event, 'relatorio-movimentacoes')">Movimentações</a>
                        <a href="#" onclick="openTab(event, 'relatorio-estoque')">Estoque</a>
                    </div>
                    <div id="relatorio-movimentacoes" class="tab-content">
                        <form method="GET" class="search-form">
                            <input type="hidden" name="page" value="relatorio">
                            <input type="hidden" name="tipo_relatorio" value="movimentacoes">
                            <div class="form-group">
                                <label for="busca_relatorio">Medicamento</label>
                                <input type="text" id="busca_relatorio" name="busca_relatorio" value="<?php echo htmlspecialchars($report_search_term); ?>" placeholder="Buscar por nome...">
                            </div>
                            <div class="form-group">
                                <label for="movimentacao_tipo">Tipo de Movimentação</label>
                                <select id="movimentacao_tipo" name="movimentacao_tipo">
                                    <option value="">Todos</option>
                                    <option value="entrada" <?php echo ($movimentacao_tipo == 'entrada') ? 'selected' : ''; ?>>Entrada</option>
                                    <option value="saida" <?php echo ($movimentacao_tipo == 'saida') ? 'selected' : ''; ?>>Saída</option>
                                    <?php if ($_SESSION['operador_nivel'] == 'operador'): ?>
                                        <option value="pendente" <?php echo ($movimentacao_tipo == 'pendente') ? 'selected' : ''; ?>>Pendente</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="data_inicio">Data Início</label>
                                <input type="date" id="data_inicio" name="data_inicio" value="<?php echo htmlspecialchars($start_date); ?>">
                            </div>
                            <div class="form-group">
                                <label for="data_fim">Data Fim</label>
                                <input type="date" id="data_fim" name="data_fim" value="<?php echo htmlspecialchars($end_date); ?>">
                            </div>
                            <?php if ($_SESSION['operador_nivel'] == 'administrador'): ?>
                                <div class="form-group">
                                    <label for="operador_relatorio">Operador</label>
                                    <select id="operador_relatorio" name="operador_relatorio">
                                        <option value="">Todos</option>
                                        <?php foreach (getOperadores($conn) as $operador): ?>
                                            <option value="<?php echo $operador['id']; ?>" <?php echo ($report_operator_id == $operador['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($operador['nome']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <button type="submit">Filtrar</button>
                        </form>
                        <div class="export-buttons">
                            <?php if (!empty($movimentacoes) || isset($_GET['busca_relatorio'])): ?>
                                <a href="?page=relatorio&tipo_relatorio=movimentacoes&export_xls=1&busca_relatorio=<?php echo urlencode($report_search_term); ?>&data_inicio=<?php echo urlencode($start_date); ?>&data_fim=<?php echo urlencode($end_date); ?>&operador_relatorio=<?php echo $report_operator_id; ?>&movimentacao_tipo=<?php echo urlencode($movimentacao_tipo); ?>" class="button">Exportar para Excel</a>
                                <button onclick="window.print()" class="button">Imprimir</button>
                            <?php endif; ?>
                        </div>
                        <div class="report-header">
                            <p>Período: <?php echo (!empty($start_date) ? htmlspecialchars($start_date) : 'Início'); ?> a <?php echo (!empty($end_date) ? htmlspecialchars($end_date) : 'Fim'); ?></p>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Medicamento</th>
                                    <th>Tipo</th>
                                    <th>Quantidade</th>
                                    <th>Estoque Final</th>
                                    <th>Operador</th>
                                    <th>Observação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($movimentacoes)): ?>
                                    <tr><td colspan="7">Nenhuma movimentação encontrada para os filtros aplicados.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($movimentacoes as $mov): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y H:i', strtotime($mov['data_movimentacao'])); ?></td>
                                            <td><?php echo htmlspecialchars($mov['medicamento_nome']); ?></td>
                                            <td class="text-capitalize"><?php echo htmlspecialchars($mov['tipo']); ?></td>
                                            <td><?php echo htmlspecialchars($mov['quantidade']); ?></td>
                                            <td><?php echo htmlspecialchars($mov['estoque_atual']); ?></td>
                                            <td><?php echo htmlspecialchars($mov['operador_nome']); ?></td>
                                            <td><?php echo htmlspecialchars($mov['observacao']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div id="relatorio-estoque" class="tab-content hidden">
                        <form method="GET" class="search-form">
                            <input type="hidden" name="page" value="relatorio">
                            <input type="hidden" name="tipo_relatorio" value="estoque">
                            <div class="form-group">
                                <label for="busca_relatorio">Medicamento</label>
                                <input type="text" id="busca_relatorio" name="busca_relatorio" value="<?php echo htmlspecialchars($report_search_term); ?>" placeholder="Buscar por nome...">
                            </div>
                            <button type="submit">Filtrar</button>
                        </form>
                        <div class="export-buttons">
                            <?php if (!empty($estoque) || isset($_GET['busca_relatorio'])): ?>
                                <a href="?page=relatorio&tipo_relatorio=estoque&export_xls=1&busca_relatorio=<?php echo urlencode($report_search_term); ?>" class="button">Exportar para Excel</a>
                                <button onclick="window.print()" class="button">Imprimir</button>
                            <?php endif; ?>
                        </div>
                        <div class="report-header">
                            <p>Data: <?php echo date('d/m/Y H:i'); ?></p>
                        </div>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Medicamento</th>
                                    <th>Descrição</th>
                                    <th>Concentração</th>
                                    <th>Apresentação</th>
                                    <th>Estoque Atual</th>
                                    <th>Quantidade Mínima</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($estoque)): ?>
                                    <tr><td colspan="8">Nenhum medicamento encontrado.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($estoque as $med):
                                        $status = '';
                                        $class = '';
                                        if ($med['quantidade'] == 0) {
                                            $status = 'ESGOTADO';
                                            $class = 'critical-stock';
                                        } elseif ($med['quantidade'] <= $med['quantidade_minima']) {
                                            $status = 'BAIXO ESTOQUE';
                                            $class = 'low-stock';
                                        } else {
                                            $status = 'OK';
                                        }
                                    ?>
                                        <tr class="<?php echo $class; ?>">
                                            <td><?php echo htmlspecialchars($med['id']); ?></td>
                                            <td><?php echo htmlspecialchars($med['nome']); ?></td>
                                            <td><?php echo htmlspecialchars($med['descricao']); ?></td>
                                            <td><?php echo htmlspecialchars($med['concentracao']); ?></td>
                                            <td><?php echo htmlspecialchars($med['apresentacao']); ?></td>
                                            <td><?php echo htmlspecialchars($med['quantidade']); ?></td>
                                            <td><?php echo htmlspecialchars($med['quantidade_minima']); ?></td>
                                            <td><?php echo $status; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php elseif ($page == 'operadores' && $_SESSION['operador_nivel'] == 'administrador'): ?>
                <h2>Gerenciar Operadores</h2>
                <form method="POST" id="operadorForm" action="index.php?page=operadores">
                    <div class="form-group">
                        <label for="nome_operador">Nome Completo</label>
                        <input type="text" id="nome_operador" name="nome" required placeholder="Digite o nome completo">
                    </div>
                    <div class="form-group">
                        <label for="usuario_operador">Nome de Usuário</label>
                        <input type="text" id="usuario_operador" name="usuario" required placeholder="Digite o nome de usuário para login">
                    </div>
                    <div class="form-group">
                        <label for="senha_operador">Senha</label>
                        <input type="password" id="senha_operador" name="senha" required placeholder="Digite a senha">
                    </div>
                    <div class="form-group">
                        <label for="confirmar_senha">Confirmar Senha</label>
                        <input type="password" id="confirmar_senha" name="confirmar_senha" required placeholder="Confirme a senha">
                    </div>
                    <div class="form-group">
                        <label for="nivel_acesso">Nível de Acesso</label>
                        <select id="nivel_acesso" name="nivel_acesso" required>
                            <option value="operador">Operador</option>
                            <option value="administrador">Administrador</option>
                        </select>
                    </div>
                    <button type="submit" name="cadastrar_operador">Adicionar Operador</button>
                </form>
                <h2>Operadores Cadastrados</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Usuário</th>
                            <th>Nível</th>
                            <th>Data de Cadastro</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($operadores)): ?>
                            <tr><td colspan="6">Nenhum operador cadastrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($operadores as $op): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($op['id']); ?></td>
                                    <td><?php echo htmlspecialchars($op['nome']); ?></td>
                                    <td><?php echo htmlspecialchars($op['usuario']); ?></td>
                                    <td class="text-capitalize"><?php echo htmlspecialchars($op['nivel_acesso']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($op['data_cadastro'])); ?></td>
                                    <td>
                                        <?php if ($op['id'] != $_SESSION['operador_id']): ?>
                                            <button onclick="openModal('modal-reset-<?php echo $op['id']; ?>')" class="warning">Redefinir Senha</button>
                                            <form method="POST" onsubmit="return confirm('Tem certeza que deseja excluir este operador? Esta ação não pode ser desfeita.');" style="display:inline;">
                                                <input type="hidden" name="operador_id" value="<?php echo htmlspecialchars($op['id']); ?>">
                                                <button type="submit" name="excluir_operador" class="delete">Excluir</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <div id="modal-reset-<?php echo $op['id']; ?>" class="modal">
                                    <div class="modal-content">
                                        <span class="close" onclick="closeModal('modal-reset-<?php echo $op['id']; ?>')">&times;</span>
                                        <h2>Redefinir Senha para <?php echo htmlspecialchars($op['nome']); ?></h2>
                                        <form method="POST" action="index.php?page=operadores">
                                            <input type="hidden" name="operador_id" value="<?php echo htmlspecialchars($op['id']); ?>">
                                            <div class="form-group">
                                                <label for="nova_senha_<?php echo $op['id']; ?>">Nova Senha</label>
                                                <input type="password" id="nova_senha_<?php echo $op['id']; ?>" name="nova_senha" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="confirmar_senha_<?php echo $op['id']; ?>">Confirmar Senha</label>
                                                <input type="password" id="confirmar_senha_<?php echo $op['id']; ?>" name="confirmar_senha" required>
                                            </div>
                                            <button type="submit" name="resetar_senha">Redefinir Senha</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<script>
    // Previne cache do navegador
    window.onpageshow = function(event) {
        if (event.persisted) {
            window.location.reload();
        }
    };

    // Previne uso do botão voltar após logout
    window.history.pushState(null, null, window.location.href);
    window.onpopstate = function() {
        window.history.pushState(null, null, window.location.href);
    };

    // Função para logout seguro
    function secureLogout() {
        // Limpa dados de sessão no cliente
        sessionStorage.clear();
        localStorage.clear();
        
        // Redireciona para logout
        window.location.href = 'index.php?logout=1';

    }

    function openTab(evt, tabName) {
        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabcontent.length; i++) {
            tabcontent[i].classList.add("hidden");
        }
        tablinks = document.getElementsByClassName("tab-links")[0].getElementsByTagName("a");
        for (i = 0; i < tablinks.length; i++) {
            tablinks[i].classList.remove("active");
        }
        document.getElementById(tabName).classList.remove("hidden");
        evt.currentTarget.classList.add("active");
    }

    function openModal(modalId) {
        document.getElementById(modalId).style.display = "block";
    }

    function closeModal(modalId) {
        document.getElementById(modalId).style.display = "none";
    }

    window.onclick = function(event) {
        var modals = document.getElementsByClassName("modal");
        for (var i = 0; i < modals.length; i++) {
            if (event.target == modals[i]) {
                modals[i].style.display = "none";
            }
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        // Verifica se está na página de login
        var isLoginPage = <?php echo ($page == 'login' ? 'true' : 'false'); ?>;
        
        if (isLoginPage) {
            // Adiciona efeito de loading no botão de login
            var loginForm = document.querySelector('.login-form');
            var loginButton = document.getElementById('loginButton');
            var buttonText = loginButton.querySelector('.button-text');
            var buttonIcon = loginButton.querySelector('.button-icon');
            var loadingSpinner = loginButton.querySelector('.loading-spinner');
            
            loginForm.addEventListener('submit', function() {
                buttonText.textContent = 'Entrando...';
                buttonIcon.style.display = 'none';
                loadingSpinner.style.display = 'inline';
                loginButton.disabled = true;
                loginButton.style.opacity = '0.7';
            });
            
            // Adiciona efeito de foco nos campos
            var inputs = document.querySelectorAll('.login-form input');
            inputs.forEach(function(input) {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'scale(1.02)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'scale(1)';
                });
            });
        } else {
            // Adiciona listener para teclas de atalho
            document.addEventListener('keydown', function(e) {
                // Previne Ctrl+R (refresh)
                if (e.ctrlKey && e.key === 'r') {
                    e.preventDefault();
                }
                // Previne F5
                if (e.key === 'F5') {
                    e.preventDefault();
                }
            });
        }
        
        var tabName = "<?php echo ($page == 'relatorio' && $report_type == 'estoque') ? 'relatorio-estoque' : ($page == 'relatorio' ? 'relatorio-movimentacoes' : 'none'); ?>";
        if (tabName !== 'none') {
            var tab = document.getElementById(tabName);
            if (tab) {
                tab.classList.remove("hidden");
                var tablinks = document.getElementsByClassName("tab-links");
                for (var i = 0; i < tablinks.length; i++) {
                    var links = tablinks[i].getElementsByTagName("a");
                    for (var j = 0; j < links.length; j++) {
                        if (links[j].getAttribute("onclick").includes(tabName)) {
                            links[j].classList.add("active");
                        }
                    }
                }
            }
        }
    });
</script>
</body>
</html>