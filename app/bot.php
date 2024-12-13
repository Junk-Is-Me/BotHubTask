<?php
require '/var/www/web/php_bot/vendor/autoload.php';
require 'config.php';

use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\Update;

$bot_api_key = BOT_API_KEY;
$bot_username = BOT_USERNAME;

$db_host = DB_HOST;
$db_name = DATABASE;
$db_user = DB_USER;
$db_pass = DB_PASS;

$memory_limit = 1024 * 1024 * 1024;
$max_transaction_amount = 10000;

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Создание таблицы, если её нет
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        telegram_id BIGINT UNIQUE NOT NULL,
        user_name VARCHAR(255) DEFAULT NULL,
        balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00
    ) ENGINE=InnoDB;");

    $telegram = new Telegram($bot_api_key, $bot_username);
    $telegram->useGetUpdatesWithoutDatabase();

    while (true) {
        try {
            // Проверка использования памяти
            if (memory_get_usage() > $memory_limit) {
                echo "Превышен лимит памяти, завершение работы.\n";
                break; // Прерываем выполнение программы, если память превышена
            }

            $server_response = $telegram->handleGetUpdates();

            if ($server_response->isOk()) {
                $result = $server_response->getResult();

                foreach ($result as $message_item) {
                    $message = $message_item->getMessage();

                    $telegram_id = $message->getFrom()->getId();
                    $message_text = trim($message->getText());
                    $user_name = $message->getFrom()->getFirstName();

                    try {
                        $pdo->beginTransaction();

                        // Проверка пользователя в базе данных
                        $stmt = $pdo->prepare("SELECT balance FROM users WHERE telegram_id = :telegram_id FOR UPDATE");
                        $stmt->execute(['telegram_id' => $telegram_id]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$user) {
                            // Добавление нового пользователя
                            $pdo->prepare("INSERT INTO users (telegram_id, user_name) VALUES (:telegram_id, :user_name)")
                                ->execute(['telegram_id' => $telegram_id, 'user_name' => $user_name]);

                            $response_text = "Добро пожаловать $user_name! Ваш аккаунт был создан с балансом $0.00";
                        } elseif (is_numeric($message_text)) {
                            // Обработка ввода суммы
                            $amount = (float) $message_text;

                            if ($amount > $max_transaction_amount) {
                                $response_text = "Ошибка: максимальная сумма транзакции $max_transaction_amount.";
                            } else {
                                $new_balance = $user['balance'] + $amount;

                                if ($new_balance < 0) {
                                    $response_text = 'Ошибка: недостаточно средств для списания. Ваш баланс $' . number_format($user['balance'], 2);
                                } else {
                                    // Обновление баланса
                                    $pdo->prepare("UPDATE users SET balance = :balance WHERE telegram_id = :telegram_id")
                                        ->execute(['balance' => $new_balance, 'telegram_id' => $telegram_id]);

                                    $response_text = 'Транзакция выполнена успешно! Ваш баланс $' . number_format($new_balance, 2);
                                }
                            }
                        } else {
                            $response_text = 'Ошибка ввода. Пожалуйста пришлите число.';
                        }
                        $pdo->commit(); // Фиксируем транзакцию
                    } catch (Exception $e) {
                        $pdo->rollBack(); // Откатываем транзакцию в случае ошибки
                        $response_text = 'Ошибка обработки запроса: ' . $e->getMessage();
                        error_log("Ошибка: " . $e->getMessage());
                    }

                    Request::sendMessage([
                        'chat_id' => $telegram_id,
                        'text' => $response_text
                    ]);
                }
            }
        } catch (Exception $e) {
            echo 'Error: ' . $e->getMessage() . "\n";
        }
        sleep(1);
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
