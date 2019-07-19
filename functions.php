<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix_perenos/connect.php';

/**
 * @param array $tables
 * @param string $prefix
 * @return string
 * Функция для создания копии боевых ьаблиц в новые без клюдчей, для
 * предотвращения возникновения ошибки первичных ключей
 */
function createTableForImport($tables = array(), $prefix = 'import_', $connect_from){

    if (!empty($tables) && $prefix != ""){
        //задаем данные для подключения откуда будет импорт

        try {
            $dbh = new PDO($connect_from['DSN'], $connect_from['LOGIN'], $connect_from['PASS']);
            $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            //проходим массив таблиц копии которых нам нужны
            foreach ($tables as $table) {

                //проверяем существование этой таблицы
                $str_query = "SELECT COUNT(*) AS LINE_COUNT FROM `information_schema`.`TABLES` WHERE TABLE_NAME = '" . $table ."'";
                $res = $dbh->query($str_query);
                $mas_res = $res->fetch();

                if ($mas_res['LINE_COUNT']){

                    $dbh->exec('USE bitrix24prod;');

                    //создаем эти копии если все проверки успешны
                    $DROPAllOldTable = "DROP TABLE IF EXISTS " . $prefix . $table . ";";
                    $createNewTable = "CREATE TABLE bitrix24prod." . $prefix . $table . " SELECT * FROM bitrix24prod." . $table . ";";

                    $dbh->exec($DROPAllOldTable);
                    $dbh->exec($createNewTable);

                } else {
                    exit('Таблицы ' . $table . ' не существует');
                }
            }

        } catch (PDOException $e) {
            exit('Ошибка подключения ' . $e->getMessage());
        }
    }
    return 'Все копии таблиц успешно созданы с преффиксом ' . $prefix ;
}


/**
 * @param array $mas_tables
 * @param string $preffix
 * @param $dop_string
 * @param $path_to_file
 *
 * после экспорта в файл копий созданных таблиц мы производим добавление строк с очисткой боевых таблиц в бд
 * и вставка туда данных из импортированных таблиц
 */
function SetOriginalData($mas_tables = array(), $preffix = 'import_', $dop_string, $path_to_file){

    if (!empty($mas_tables) && $preffix != "" && $path_to_file != ""){
        if (filesize($path_to_file) != 0){

            if ($dop_string != ""){
                addToFileEnd($path_to_file, 'a+', PHP_EOL . $dop_string . PHP_EOL);
            }

            editColumnsParam();

            foreach ($mas_tables as $table){
                $file_content =  file_get_contents($path_to_file);

                addToFileStart($path_to_file, 'w+', 'TRUNCATE ' . $table . ';' . PHP_EOL . $file_content);

                addToFileEnd($path_to_file, 'a+', 'INSERT INTO ' . $table . ' SELECT * FROM ' . $preffix . $table . ';' . PHP_EOL);
            }
        } else {
            exit('Проверте корректность файла');
        }
    }
}

/**
 * @param $connect_to
 * @param $connect_from
 * @param $table_name
 * @param $prefix
 * @return string
 */
function editColumnsParam($connect_to, $connect_from, $table_name, $prefix){
    $result_edit = '';

    if (!empty($connect_to) &&  !empty($connect_from) && $table_name != ""){

        try {
            $db_from = new PDO($connect_from['DSN'], $connect_from['LOGIN'], $connect_from['PASS']);
            $db_to = new PDO($connect_to['DSN'], $connect_to['LOGIN'], $connect_to['PASS']);

            $db_from->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db_to->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $query_bd_from = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '" . $table_name . "' AND TABLE_SCHEMA = '" . $connect_from['DB_NAME'] . "';";
            $query_bd_to = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '" . $table_name . "' AND TABLE_SCHEMA = '" . $connect_to['DB_NAME'] . "';";

            $res_from = $db_from->query($query_bd_from);
            $res_to = $db_to->query($query_bd_to);

            $mas_columns_from = $res_from->fetchAll();
            $mas_columns_to = $res_to->fetchAll();

            foreach ($mas_columns_to as $key => $columns_to) {
                if ($columns_to['COLUMN_NAME'] != $mas_columns_from[$key]['COLUMN_NAME']){

                    $str_query = "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '" . $table_name . "' AND COLUMNS.TABLE_SCHEMA = '" . $connect_to['DB_NAME'] . "' AND COLUMN_NAME = '" . $columns_to['COLUMN_NAME'] . "';";
                    $getColumn = $db_to->query($str_query);
                    $result = $getColumn->fetch();

                    $column_before = $mas_columns_to[$key - 1]['COLUMN_NAME'];

                    if (!getTrueColumns($columns_to['COLUMN_NAME'], $mas_columns_from)){
                        $result_edit.= "ALTER TABLE " . $prefix . $table_name . " ADD " . $columns_to['COLUMN_NAME'] . getDefaultAndCollate($result) . " AFTER " .  $column_before . ";";
                    }

                    if (getTrueColumns($columns_to['COLUMN_NAME'], $mas_columns_from)){
                        $result_edit.= "ALTER TABLE " . $prefix . $table_name . " MODIFY " . $columns_to['COLUMN_NAME'] . getDefaultAndCollate($result) . " AFTER " . $column_before . ";";
                    }
                }
            }
        } catch (PDOException $e) {
            exit('Ошибка подключения ' . $e->getMessage());
        }
    }
    return $result_edit;
}

/**
 * @param $str
 * @param $mas
 * @return bool
 *
 * ф-я определения колонок
 */
function getTrueColumns($str, $mas){
    foreach ($mas as $elemColumn) {
        if (in_array($str, $elemColumn)) return true;
    }
    return false;
}

/**
 * @param $path_to_file
 * @param $mode
 * @param $string
 *
 * ф-я записи в конец файла
 */
function addToFileEnd($path_to_file, $mode, $string){
    if ($path_to_file != "" && $mode != "" && $string != ""){
        $file_add_end = fopen($path_to_file, $mode);
        fwrite($file_add_end, $string);
        fclose($file_add_end);
    }
}

/**
 * @param $path_to_file
 * @param $mode
 * @param $string
 * ф-я записи в начало файла
 */
function addToFileStart($path_to_file, $mode, $string){
    if ($path_to_file != "" && $mode != "" && $string != ""){
        $file_add_end = fopen($path_to_file, $mode);
        fwrite($file_add_end, $string);
        fclose($file_add_end);
    }
}

// функция для правильного определния дефолтногот поля
/**
 * @param $data
 * @return string
 */
function getDefaultAndCollate($data){
    $coll = '';
    $wordData = '';

    $coll = " " . $data['COLUMN_TYPE'];

    if ($data['DATA_TYPE'] == 'varchar' ||
        $data['DATA_TYPE'] == 'text' ||
        $data['DATA_TYPE'] == 'mediumtext' ||
        $data['DATA_TYPE'] == 'char' ||
        $data['DATA_TYPE'] == 'mediumtext' ||
        $data['DATA_TYPE'] == 'longtext' ||
        $data['DATA_TYPE'] == 'longtext' ||
        $data['DATA_TYPE'] == 'longtext' ||
        $data['DATA_TYPE'] == 'longtext' ||
        $data['DATA_TYPE'] == 'int') $wordData = "'" . $data['COLUMN_DEFAULT'] . "'";

    if ($data['COLLATION_NAME'] != "") $coll.= ' COLLATE ' . $data['COLLATION_NAME'];
    if ($data['COLUMN_DEFAULT'] != "") $coll.= ' DEFAULT ' . $wordData; else $coll.= ' DEFAULT';
    if ($data['IS_NULLABLE'] == 'YES') $coll.= ' NULL'; else $coll.= ' NOT NULL';

    return $coll;
}

/**
 * @param $data
 */
function debug($data){
    echo "<pre>";
    print_r($data);
    echo "</pre>";
}