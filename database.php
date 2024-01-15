<?php
class Database {

    // укажите свои учетные данные базы данных
    private $dsn = 'mysql:dbname=foxyhunter;host=localhost';
    private $username = "foxy";
    private $password = "meB7QcYVFAMHYXA9";
    public $conn;

    // получаем соединение с БД
    public function getConnection(){

        $this->conn = null;

        try {
            $this->conn = new PDO($this ->dsn, $this ->username, $this ->password);
            $this->conn->exec("set names utf8");
        } catch(PDOException $exception){
            echo "Connection error: " . $exception->getMessage();
			return NULL;
        }

        return $this->conn;
    }
}
?>