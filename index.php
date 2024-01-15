<?php
define('LETTERS', array("А", "Б", "В", "Г", "Д", "Е", "Ж", "З", "И", "К"));
define('NUMBERS', array("1", "2", "3", "4", "5", "6", "7", "8", "9", "10"));
define('FOXES_TOTAL', 8);

require_once("core.php");
require_once("database.php");
require_once("game.php");

define('BOT_NAME', 'FoxyHuntBot');
define('BOT_TOKEN', '1877569645:AAFgRqgWhMgSQx6465-NWSB91igGqLKJHbU');
define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
date_default_timezone_set('Europe/Moscow');

function apiRequestWebhook($method, $parameters) {
  if (!is_string($method)) {
    error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    error_log("Parameters must be an array\n");
    return false;
  }

  $parameters["method"] = $method;

  $payload = json_encode($parameters);
  header('Content-Type: application/json');
  header('Content-Length: '.strlen($payload));
  echo $payload;

  return true;
}

function exec_curl_request($handle) {
  $response = curl_exec($handle);

  if ($response === false) {
    $errno = curl_errno($handle);
    $error = curl_error($handle);
    error_log("Curl returned error $errno: $error\n");
    curl_close($handle);
    return false;
  }

  $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
  curl_close($handle);

  if ($http_code >= 500) {
    // do not wat to DDOS server if something goes wrong
    sleep(10);
    return false;
  } else if ($http_code != 200) {
    $response = json_decode($response, true);
    error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
    if ($http_code == 401) {
      throw new Exception('Invalid access token provided');
    }
    return false;
  } else {
    $response = json_decode($response, true);
    if (isset($response['description'])) {
      error_log("Request was successful: {$response['description']}\n");
    }
    $response = $response['result'];
  }

  return $response;
}

function apiRequest($method, $parameters) {
	if (!is_string($method)) {
		error_log("Method name must be a string\n");
		return false;
	}

	if (!$parameters) {
		$parameters = array();
	} else if (!is_array($parameters)) {
		error_log("Parameters must be an array\n");
		return false;
	}

	foreach ($parameters as $key => &$val) {
	// encoding to JSON array parameters, for example reply_markup
		if (!is_numeric($val) && !is_string($val)) {
			$val = json_encode($val);
		}
	}
	$url = API_URL.$method.'?'.http_build_query($parameters);

	$handle = curl_init($url);
	curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
	curl_setopt($handle, CURLOPT_TIMEOUT, 60);
	curl_setopt($handle, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
	return exec_curl_request($handle);
}

function apiRequestJson($method, $parameters) {
	if (!is_string($method)) {
		error_log("Method name must be a string\n");
		return false;
	}

	if (!$parameters) {
		$parameters = array();
	} else if (!is_array($parameters)) {
		error_log("Parameters must be an array\n");
		return false;
	}

	$parameters["method"] = $method;

	$handle = curl_init(API_URL);
	curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
	curl_setopt($handle, CURLOPT_TIMEOUT, 60);
	curl_setopt($handle, CURLOPT_POST, true);
	curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
	curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
	curl_setopt($handle, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);

	return exec_curl_request($handle);
}

function sendPhoto($chat_id, $path)
{
	$url = API_URL . "sendPhoto?chat_id=" . $chat_id ;

	$post_fields = array('chat_id'   => $chat_id,
		'photo'     => new CURLFile(realpath($path))
	);

	$ch = curl_init(); 
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		"Content-Type:multipart/form-data"
	));
	curl_setopt($ch, CURLOPT_URL, $url); 
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
	curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields); 
	$output = curl_exec($ch);
	return $output;
}

function CreateTrainGame($user)
{
	$res = new Response();
	$database = new Database();
	$db = $database->getConnection();
	if (!$db)
		return $res->SetError("Ошибка работы с БД");
	$game = new Game($db);
	$id = $game->CreateNewSolo($user);
	if (!$id)
		return $res->SetError("Ошибка добавления игры в БД");
	$game->Generate();
	$saved = $game->Save();
	if (!$saved)
		return $res->SetError("Ошибка сохранения новой игры после генерации поля");
	$res->game = $game;
	$s = "Поле готово. Жду первого выстрела.\n" ;
	$s .= "<code>" . $game->GetBattle() . "</code>";
	$s .= $game->GetStats();
	return $res->SetSuccess($s);
}

function ProcessTurn($user, $text, $isPrivate)
{
	$coord = mb_strtoupper($text);
	$res = new Response();
	$database = new Database();
	$db = $database->getConnection();
	if (!$db)
		return $res->SetError("Ошибка работы с БД");
	$game = new Game($db);
	$loaded = $game->Load($user);
	if (!$loaded)
		return $res->SetError("Игра с вашим участием не найдена.\nВы можете начать новую игру командой /train");
	$res->game = $game;
	if ($game->state == 'inited')
		return $res->SetError("Игра не начата. Ожидается задание полей обоими участниками.");
	if ($game->state == 'finished')
		return $res->SetError("Игра уже завершена.");
	$needUser = $game->kind == "pair" ? $game->users[$game->turn % 2] : $user;
	if ($game->kind == 'pair' && strcmp($needUser, $user) != 0)
		return $res->SetError("Сейчас должен ходить игрок @" . $needUser);
	$answer = "";
	if (($game->period > 0 && time() - $game->lastTime > $game->period) || strcmp($coord, "--") == 0 || strcmp($coord, "—") == 0)
	{
		$game->turn++;
		$game->Save();
		$answer = "(пропуск хода)";
	}
	else
	{
		if (in_array($coord, $game->turns))
			return $res->SetError("Такой ход уже был сделан ранее: " . implode(" ", $game->turns));
		$cell = $game->ProcessTurn($coord);
		if ($cell == "")
			return $res->SetError("Что-то пошло не так. Похоже, координата [" . $text . "] не найдена на поле");
		$game -> Save();
		$answer = $coord . " = " . $cell;
	}
	$s = $answer . "\n";
	$s .= "<code>" . $game->GetBattle() . "</code>";
	$s .= $game->GetStats() . "\n";
	$add = "";
	if ($game->kind == 'pair')
	{	
		$next = $game->users[$game->turn % 2];
		if ($isPrivate)
			$add .="Игрок @" . $user . " сделал ход: " . $answer. ".\n";
		if (strcmp($next, $user) != 0 && $game->foxesCount < $game->foxesTotal)
			$add .= "Ход переходит игроку @" . $next . ".\n";
		if ($game->period > 0)
			$add .= "Время на ход - " . $game->period . " секунд, до " . date('G:i:s', time());
	}
	if ($isPrivate)
	{
		$res->SetSuccess($s);
		if ($add != "")
			$res->AddMessage($add, $game->chat_id);
	}
	else
	{
		$s .= $add;
		$res->SetSuccess($s);
	}
	return $res;
}

function StopGame($user, $isPrivate, $oldMessageText = "")
{
	$res = new Response();
	$database = new Database();
	$db = $database->getConnection();
	if (!$db)
		return $res->SetError("Ошибка работы с БД");
	$game = new Game($db);
	$loaded = $game->Load($user);
	if (!$loaded)
		return $res->SetError("Игра с вашим участием не найдена.\nВы можете начать новую игру командой /train");
	$res->game = $game;
	$s = "Игра <b>остановлена</b> по инициативе @$user.\n";
	if ($game->fieldStr != "")
	{
		$s .= "<code>" . $game->ToString() . "</code>";
		$s .= "Результат @" . $user . ": " . $game->GetStats();
		if ($game->kind == "pair")
		{	
			$user2 = $game->users[0] == $user ? $game->users[1] : $game->users[0];
			$game2 = new Game($db);
			$loaded = $game2->Load($user2);
			if (!$loaded)
				return $res->SetError("Игра с участием " . $user2 . " не найдена.");
			if ($game->fieldStr != "")
			{
				$s .= "\n<code>" . $game2->ToString() . "</code>";
				$s .= "Результат @" . $user2 . ": " . $game2->GetStats();
			}
		}
	}
	if ($game->kind == "solo")
		$s .= "\nВы можете начать новую игру командой /train";
	else
		$s .= "\nВы можете начать новую игру командой /game";
	$removed = $game->Remove($user);
	if (!$removed)
		return $res->SetError("Ошибка при удалении игры");
	if ($oldMessageText)
	{
		$res->SetEdit($oldMessageText);
		$res->AddMessage($s, $game->chat_id);
		return $res;
	}
	else
	if ($isPrivate && $game->kind == "pair")
	{
		$res->AddMessage($s, $game->chat_id);
		return $res;
	}
	else
		return $res->SetSuccess($s);
}

function StartBot($user, $text)
{
	$res = new Response();
	$s = "Добро пожаловать в игру 'Охота на лис'!\n";
	$s .= "Бот понимает следующие команды:\n";
	$s .= "/train - Начать одиночную тренировочную игру\n";
	$s .= "/stop - Остановить игру и показать исходное поле\n";
	return $res->SetSuccess($s);
}

function InitGame($user1, $user2, $chat_id)
{
	$res = new Response();
	$database = new Database();
	$db = $database->getConnection();
	if (!$db)
		return $res->SetError("Ошибка работы с БД");
	$game = new Game($db);
	$inited = $game->InitNewPair($user1, $user2, $chat_id);
	if (!$inited)
		return $res->SetError("Ошибка инициации игры");
	$s = GetInitText($game);
	$res->SetSuccess($s);
	$res->SetMarkup(GetInitKeyboard());
	return $res;
}

function GetInitText($game)
{
	$s = "Инициирована игра один-на-один: @" . $game->users[0] . " и @" . $game->users[1] . "\n";
	if ($game ->period == 0)
		$s.= "Без учета времени";
	else
		$s.= "Блиц. На ход даётся " . $game->period . " секунд.\n";
	return $s;
}

function GetInitKeyboard()
{
	$button01 = array("text"=>"Без времени", "callback_data"=>'/period0');
	$button02 = array("text"=>"30 сек", "callback_data"=>'/period30');
	$button03 = array("text"=>"60 сек", "callback_data"=>'/period60');
	$button04 = array("text"=>"5 мин", "callback_data"=>'/period300');
	$button1 = array("text"=>"Задать поле", "url"=>"http://telegram.me/" . BOT_NAME . "?start=setfield");
    $button2 = array("text"=>"Отмена", "callback_data"=>'/stop');
    $inline_keyboard = [[$button01, $button02, $button03, $button04], [$button1, $button2]];
    $keyboard=array("inline_keyboard"=>$inline_keyboard);
	return json_encode($keyboard);
}

function SetField($user, $text)
{
	$res = new Response();
	$gameId = substr($text, 10);
	$s = "Нужно задать поле для игры.\n";
	$s .= "Доступны варианты:\n";
	$s .= "- Отправьте боту в сообщении массив координат с лисами, разделенных пробелами, например 'а1 б2 в3 г4 д5 е6 ж7 з8'.\n";
	$s .= "- Скопируйте <b>расчитанное</b> поле из гуглотаблицы (только само поле 10 на 10 без подписей) и отправьте его боту в сообщении.\n";
	$s .= "- Можно <b>сгенерировать</b> случайное поле, кнопка под собщением.\n";
	$s .= "После этого проверьте, что поле введено верно и нажмите кнопку 'Готово'.\n";
	$res->SetSuccess($s);
	$res->SetMarkup(GetSetFieldKeyboard($gameId, ""));
	return $res;
}

function GetSetFieldKeyboard($gameId, $fieldStr)
{
    $inline_button1 = array("text"=>"Генерировать случайное", "callback_data"=>"setfieldRnd");
    $inline_button2 = array("text"=>"Отмена", "callback_data"=>'/stop');
    $inline_keyboard = [[$inline_button1], [$inline_button2]];
	if ($fieldStr)
	{
		$inline_keyboard[] = [array("text"=>"Готово", "callback_data"=>'setfieldReady ' . $fieldStr)];
	}
    $keyboard=array("inline_keyboard"=>$inline_keyboard);
	return json_encode($keyboard);
}

function SetFieldRnd($text)
{
	$res = new Response();
	$index = strpos($text, "\n");
	if ($index <= 0)
		$index = strlen($text);
	$text = substr($text, 0, $index);
	$index = strpos($text, "_");
	if ($index == -1)
		$index = 0;
	$gameId = substr($text, $index);
	$game = new Game(null);
	$game->Generate();
	$s = $text . "\n";
	$s .= "<code>" . $game->ToString() . "</code>\n";
	$s .= "Подтверждаете?\n";
	$res->SetEdit($s);
	$res->SetMarkup(GetSetFieldKeyboard($gameId, $game->GetFoxesCoords()));
	return $res;
}

function SetField8($user, $text)
{
	$res = new Response();
	$gameId = "";
	$database = new Database();
	$db = $database->getConnection();
	if (!$db)
		return $res->SetError("Ошибка работы с БД");
	$game = new Game($db);
	$loaded = $game->Load($user);
	if (!$loaded)
		return $res->SetError("Не удалось задать поле. Похоже, игра не была инициирована или уже завершена.");
	$ok = $game->SetField8($text);
	if ($ok !== true)
		return $res->SetError("Не удалось распознать в сообщении 8 координат. Проверьте отсутствие пробелов в начале и в конце сообщения. Координаты должны быть разделены одиночным пробелом, например 'а1 б2 в3 г4 д5 е6 ж7 з8'.");
	$s = "Задание поля:" . "\n";
	$s .= "<code>" . $game->ToString() . "</code>\n";
	$s .= "Подтверждаете?\n";
	$res->SetSuccess($s);
	$res->SetMarkup(GetSetFieldKeyboard($gameId, $game->GetFoxesCoords()));
	return $res;
}

function SetField10($user, $text, $isPrivate)
{
	$res = new Response();
	$gameId = "";
	$database = new Database();
	$db = $database->getConnection();
	if (!$db)
		return $res->SetError("Ошибка работы с БД");
	$game = new Game($db);
	$loaded = $game->Load($user);
	if (!$loaded)
		return $res->SetError("Не удалось задать поле. Похоже, игра не была инициирована или уже завершена.");
	$ok = $game->SetField10($text);
	if ($ok !== true)
		return $res->SetError("Не удалось распознать поле: " . $ok);
	$s = "Задание поля:" . "\n";
	$s .= "<code>" . $game->ToString() . "</code>\n";
	$s .= "Подтверждаете?\n";
	$res->SetSuccess($s);
	$res->SetMarkup(GetSetFieldKeyboard($gameId, $game->GetFoxesCoords()));
	return $res;
}

function SetFieldReady($user, $data, $oldMessage)
{
	$res = new Response();
	$fieldStr = substr($data, 14);
	
	$database = new Database();
	$db = $database->getConnection();
	if (!$db)
		return $res->SetError("Ошибка работы с БД");
	$game = new Game($db);
	$count = $game->SetField($user, $fieldStr);
	if (!$count)
		return $res->SetError("Ошибка задания поля в БД: " . $fieldStr);
	$index = strpos($oldMessage, "\n");
	if ($index <= 0)
		$index = strlen($oldMessage);
	$s = substr($oldMessage, 0, $index) . "\n";
	$s .= "<code>" . $game->ToString() . "</code>\n";
	$s .= "Поле сохранено. Готово игроков: " . $count . " из 2.";
	$res->SetEdit($s);
	if ($count == 2)
	{
		$add = "Игра между @" . $game->users[0] . " и @" . $game->users[1] . " начата.\nПервым ходит @" . $game->users[0] . ".\n";
		if ($game->period > 0)
			$add .= "Время на ход - " . $game->period . " секунд, до " . date('G:i:s', time());
		$res->AddMessage($add, $game->chat_id);
	}
	return $res;
}

function SetPeriod($user, $periodStr)
{
	$res = new Response();
	$period = intval($periodStr);
	if (strlen($periodStr) == 0 || $period < 0)
	{
		return $res->SetError("Неверное значение периода: " . $periodStr);
	}
	$database = new Database();
	$db = $database->getConnection();
	if (!$db)
		return $res->SetError("Ошибка работы с БД");
	$game = new Game($db);
	$loaded = $game->Load($user);
	if (!$loaded)
		return $res->SetError("Игра с вашим участием не найдена");
	$change = $game->SetPeriod($period);
	$s = GetInitText($game);
	$res->SetEdit($s);
	$res->SetMarkup(GetInitKeyboard());
	$s2 = "В игре @". $game->users[0] . " vs @". $game->users[1] . " изменены условия.\n";
	$s2 .= "Период на раздумья " . ($game->period == 0 ? "не ограничен." : $game->period . " секунд.");
	$res->AddMessage($s2, $game->chat_id);
	return $res;
}

function processMessage($message) {
	// process incoming message
	$message_id = $message['message_id'];
	$chat_id = $message['chat']['id'];

	//sendPhoto($chat_id, "images/img.png");
	//apiRequestWebhook("sendMessage", array('chat_id' => $chat_id, "text" => "DEBUG: ". realpath("images/img.png")));	
	//return;

	$res = new Response();
	if (isset($message['text'])) {
		// incoming text message
		$text = $message['text'];
		$from = $message['from'];
		$username = $from['username'];
		$postfix = "";
		if (substr($text, 0, 2) == "/1" || substr($text, 0, 2) == "/2" || substr($text, 0, 2) == "/3")
		{
			$postfix = substr($text, 1, 1);
			$text = substr($text, 2);
			$username .= $postfix;
		}
		$userId = $from['id'];
		$userFirstName = $from['first_name'];
		$isPrivate = $chat_id == $userId;
		if (strpos($text, "/") === 0)
		{
			if (substr($text, 0, 6) == "/start")
			{
				$mes = substr($text, 6);
				if (substr($mes, 0, 9) == " setfield")
					$res = SetField($username, $mes);
				else
					$res = StartBot($username, $text);
			}
			else if (substr($text, 0, 6)== "/train")
				$res = CreateTrainGame($username);
			else if (substr($text, 0, 5) == "/stop")
				$res = StopGame($username, $isPrivate);
			else if (substr($text, 0, 5) == "/game")
			{
				$user2 = strlen($text) > 6 ? substr($text, 6) : "";
				if (strlen($user2) > 0 && strcmp($user2, "FoxyHuntBot") != 0 && strcmp($user2, $username) != 0)
					$res = InitGame($username, $user2, $chat_id);
				else
					$res->SetSuccess("Нужен второй игрок. Присоединяйтесь: /game@".$username);
			}
			else
				;//$res->SetError("Неизвестная команда");
		}
		else if (((mb_strlen($text) == 2 || mb_strlen($text) == 3) && (in_array(mb_strtoupper(mb_substr($text, 0, 1)), LETTERS) && in_array(mb_substr($text, 1), NUMBERS))) || strcmp($text, "—") == 0)
			$res = ProcessTurn($username, $text, $isPrivate);
		else if (count(explode("\n", $text)) == 10)
			$res = SetField10($username, $text, $isPrivate);
		else if ($isPrivate && count(explode(" ", $text)) == FOXES_TOTAL)
			$res = SetField8($username, $text);
		else if ($isPrivate)
			;//$res->SetError("Что-то я не понял... " . count(explode("\n", $text)));
		else
			; // do nothing

		$res->chat_id = $chat_id;
		$res->message_id = $message_id;

		ProcessResponse($res);
	} 
	else 
	{
		apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'I understand only text messages'));
	}
}

function processCallbackQuery($query)
{
	$callback_query_id = $query["id"];
	$data = $query["data"];
	$message = $query["message"];
	$chat_id = $message["chat"]["id"];
	$message_id = $message["message_id"];
	$messageText = $message["text"];
	$user = $query["from"];
	$username = $user["username"];
	$res = new Response();
	if (substr($data, 0, 11) == "setfieldRnd")
		$res = SetFieldRnd($messageText);
	else if (substr($data, 0, 13) == "setfieldReady")
		$res = SetFieldReady($username, $data, $messageText);
	else if (substr($data, 0, 7) == "/period")
		$res = SetPeriod($username, substr($data, 7));
	else if (substr($data, 0, 5) == "/stop")
		$res = StopGame($username, $isPrivate, $messageText);
	else
	{
		$res = new Response();
		$res->SetCallback($callback_query_id, $data);
	}
	$res->chat_id = $chat_id;
	$res->message_id = $message_id;
	
	ProcessResponse($res);
}

function ProcessResponse($res)
{
	if ($res->error)
	{
		/*$markup = array(
			'keyboard' => array(array("/train", "/stop")),
			'one_time_keyboard' => true,
			'resize_keyboard' => true);
		*/
		apiRequestWebhook("sendMessage", array('chat_id' => $res->chat_id, "reply_to_message_id" => $res->message_id, "text" => $res->error));
	}
	else if ($res->callback)
	{
		apiRequestWebhook('answerCallbackQuery', array("callback_query_id" => $res->callback_query_id, "text" => $res->callback));
	}
	else if ($res->edit)
	{
		$edit = array("chat_id" => $res->chat_id, "message_id" => $res->message_id , "text" => $res->newText, "parse_mode" => "HTML");
		if (isset($res->markup))
			$edit["reply_markup"] = $res->markup;
		apiRequestWebhook('editMessageText', $edit);
	}
	else if ($res->message)
	{
		$newMessage = array('chat_id' => $res->chat_id, "text" => $res->message, "parse_mode" => "HTML");
		if (isset($res->markup))
			$newMessage["reply_markup"] = $res->markup;
		if ($res->message_id)
			$newMessage["reply_to_message_id"] = $res->message_id;
		apiRequestWebhook("sendMessage", $newMessage);
	}
	
	if ($res->addMessage)
	{
		//apiRequest("sendMessage", array('chat_id' => $res->chat_id, "text" => 'I understand only text messages'));
		$newMessage = array('chat_id' => $res->add_chat_id, "text" => $res->addMessage, "parse_mode" => "HTML");
		apiRequest("sendMessage", $newMessage);
	}
}

define('WEBHOOK_URL', 'https://sungeargames.com/test/tg/');

if (php_sapi_name() == 'cli') {
  // if run from console, set or delete webhook
  apiRequest('setWebhook', array('url' => isset($argv[1]) && $argv[1] == 'delete' ? '' : WEBHOOK_URL));
  exit;
}


$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
  // receive wrong update, must not happen
  exit;
}

if (isset($update["callback_query"]))
{
	processCallbackQuery($update["callback_query"]);
}
else if (isset($update["message"]))
{
	processMessage($update["message"]);
}