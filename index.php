<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Магазин Скинов и Машин</title>
    <style>
        body { font-family: Arial, sans-serif; background: #222; color: #fff; text-align: center; padding-top: 50px; }
        .form-container { background: #333; width: 350px; margin: 0 auto; padding: 20px; border-radius: 10px; }
        input, select, button { width: 100%; margin-bottom: 15px; padding: 10px; box-sizing: border-box; }
        button { background: #28a745; color: white; border: none; cursor: pointer; font-weight: bold; }
        button:hover { background: #218838; }
    </style>
</head>
<body>

    <div class="form-container">
        <h2>Магазин сервера</h2>
        <p>Убедитесь, что вы не в игре при покупке!</p>
        
        <form action="buy.php" method="POST">
            <input type="text" name="player_name" placeholder="Ваш ник (например: Vlad_Kefirov)" required>
            
            <select name="item_type" required>
                <option value="skin">Купить Скин (100 RUB)</option>
                <option value="car">Купить Машину (500 RUB)</option>
            </select>
            
            <input type="number" name="item_id" placeholder="ID скина / машины" required min="1">
            
            <button type="submit">Оплатить и получить</button>
        </form>
    </div>

</body>
</html>
