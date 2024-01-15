<?php
// показывать сообщения об ошибках
ini_set('display_errors', 1);
error_reporting(E_ALL);

// URL домашней страницы
$home_url="http://84.38.184.131/";

// страница указана в параметре URL, страница по умолчанию одна
$page = isset($_GET['page']) ? $_GET['page'] : 1;

// установка количества записей на странице
$records_per_page = 5;

// расчёт для запроса предела записей
$from_record_num = ($records_per_page * $page) - $records_per_page;


class Response {

    public $error;
    public $message;
	public $game;
	public $markup;
	
	public $message_id;
	public $chat_id;
	
	public $edit;
	public $newText;
	
	public $callback;
	public $callback_query_id;
	
	public $addMessage;
	public $add_chat_id;
	
    public function SetError($error)
	{
        $this->error = $error;
		return $this;
    }
	
	public function SetSuccess($message)
	{
		$this->message = $message;
		return $this;
	}
	
	public function SetMarkup($markup)
	{
		$this->markup = $markup;
		return $this;
	}
	
	public function SetEdit($newText)
	{
		$this->edit = true;
		$this->newText = $newText;
		return $this;
	}
	
	public function SetCallback($callback_query_id, $text)
	{
		$this->callback_query_id = $callback_query_id;
		$this->callback = $text;
		return $this;
	}
	
	public function AddMessage($message, $chat_id)
	{
		$this->addMessage = $message;
		$this->add_chat_id = $chat_id;
	}
}

?>