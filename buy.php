<?php
require 'db.php'; // Подключаем базу

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $player_name = trim($_POST['player_name']);
    $item_type = $_POST['item_type'];
    $item_id = (int)$_POST['item_id'];
    
    // Устанавливаем цены
    $price_skin = 100; // Цена скина в RUB
    $price_car = 500;  // Цена машины в RUB

    try {
        // 1. Ищем игрока в БД
        $stmt = $pdo->prepare("SELECT id, rub, online FROM accounts WHERE name = :name");
        $stmt->execute(['name' => $player_name]);
        $player = $stmt->fetch();

        if (!$player) {
            die("Ошибка: Аккаунт с таким ником не найден.");
        }

        // Желательно, чтобы игрок был оффлайн, чтобы данные не перезаписались сервером
        if ($player['online'] > 0) {
            die("Ошибка: Выйдите из игры перед покупкой!");
        }

        // 2. Логика покупки Скина
        if ($item_type === 'skin') {
            if ($player['rub'] < $price_skin) {
                die("Ошибка: Недостаточно RUB на аккаунте. Нужно: {$price_skin}, у вас: {$player['rub']}");
            }

            // Списываем рубли и обновляем скин
            $update = $pdo->prepare("UPDATE accounts SET rub = rub - :price, skin = :skin_id WHERE id = :id");
            $update->execute([
                'price' => $price_skin,
                'skin_id' => $item_id,
                'id' => $player['id']
            ]);

            echo "Успешно! Скин #{$item_id} выдан на аккаунт {$player_name}. Списано {$price_skin} RUB.";
        }

        // 3. Логика покупки Машины
        elseif ($item_type === 'car') {
            if ($player['rub'] < $price_car) {
                die("Ошибка: Недостаточно RUB на аккаунте. Нужно: {$price_car}, у вас: {$player['rub']}");
            }

            // ПРИМЕЧАНИЕ: В твоем дампе нет таблицы с машинами. Обычно на серверах она называется `cars` или `vehicles`.
            // Здесь я привел стандартный пример добавления машины владельцу.
            
            // Списываем рубли из accounts
            $updateRub = $pdo->prepare("UPDATE accounts SET rub = rub - :price WHERE id = :id");
            $updateRub->execute(['price' => $price_car, 'id' => $player['id']]);

            // Добавляем машину в таблицу vehicles (замени 'vehicles' на название своей таблицы с авто)
            // И столбцы 'owner' и 'model' на свои.
            /*
            $insertCar = $pdo->prepare("INSERT INTO ownable_cars (owner_id, model_id) VALUES (:owner_id, :model_id)");
            $insertCar->execute([
                'owner_id' => $player_name, 
                'model_id' => $item_id
            ]);
            */

            echo "Успешно! Машина #{$item_id} выдана на аккаунт {$player_name}. Списано {$price_car} RUB. (Раскомментируй SQL запрос в коде для выдачи авто)";
        }

    } catch (PDOException $e) {
        echo "Системная ошибка: " . $e->getMessage();
    }
}
?>
