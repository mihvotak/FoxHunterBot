<?php
class Game {

    private $conn;
    private $usersTable = "users";
    private $gamesTable = "games";
	private $letters = array("А", "Б", "В", "Г", "Д", "Е", "Ж", "З", "И", "К");
	private $numbers = array("1", "2", "3", "4", "5", "6", "7", "8", "9", "10");

	public $fox = "x";
	public $foxToShow = "@";//☻
	
	public $foxesTotal = 8;

    public $id;
    public $user;
	public $users;
    public $fieldStr;
	public $field;
	public $fieldByCoord;
    public $turnsStr;
	public $turns;
	public $foxesCount = 0;

	public $kind;
	public $state;
	public $turn;
	public $chat_id;
	public $period = 0;
	public $lastTime;

    public function __construct($db){
        $this->conn = $db;
    }

	function RegisterUser($user)
	{
        $query = "SELECT * FROM ".$this->usersTable." WHERE user = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $user);
        $stmt->execute();

        $num = $stmt->rowCount();
		
		$user_id = 0;

        if($num > 0) 
		{
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $user_id = $row['id'];
		}
		else
		{
			$query = "INSERT INTO ".$this->usersTable." (user) VALUES (?)";
			$stmt = $this->conn->prepare($query);
			$stmt->bindParam(1, $user);
			$stmt->execute();
			$user_id = $this->conn->lastInsertId();
		}
		return true;
	}
	
    function CreateNewSolo($user)
	{
		$this->RegisterUser($user);

		$this->Remove($user);
		
		$query = "INSERT INTO ".$this->gamesTable."(kind, state, turn, users) VALUES ('solo', 'started', 0, ?)";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(1, $user);
		$stmt->execute();
		$this->id = $this->conn->lastInsertId();
		$this->user = $user;

		$query = "UPDATE ".$this->usersTable." SET game = :game WHERE user = :user";
		$stmt = $this->conn->prepare($query);
        $stmt->bindParam(':game', $this->id);
        $stmt->bindParam(':user', $this->user);
		$stmt->execute();

        return $this->id;
    }

    function InitNewPair($user, $user2, $chat_id)
	{
		$this->RegisterUser($user);
		$this->RegisterUser($user2);
		
		$this->Remove($user);
		$this->Remove($user2);
		
		$this->user = $user;
		$this->user2 = $user2;
		$this->users = [$user, $user2];

		$query = "INSERT INTO ".$this->gamesTable."(kind, state, turn, users, chat_id) VALUES ('pair', 'inited', 0, :users, :chat_id)";
		$stmt = $this->conn->prepare($query);
		$users = $user . " " . $user2;
        $stmt->bindParam(':users', $users);
        $stmt->bindParam(':chat_id', $chat_id);
		$stmt->execute();
		$this->id = $this->conn->lastInsertId();

		$query = "UPDATE ".$this->usersTable." SET game = :game, field = '', turns= '' WHERE user = :user OR user = :user2";
		$stmt = $this->conn->prepare($query);
        $stmt->bindParam(':game', $this->id);
        $stmt->bindParam(':user', $this->user);
        $stmt->bindParam(':user2', $this->user2);
		$stmt->execute();

        return $this->id;
    }

	function Clear()
	{
		$this->turns = array();
		
		$this->field = array();
		$this->fieldByCoord = array();
		$index = 0;
		for ($y = 0; $y < 10; $y++)
		{
			$arrIn = array();
			for ($x = 0; $x < 10; $x++)
			{
				$arrIn[] = 0;
			}
			$this->field[]=$arrIn;
		}
	}

	function CalculateField()
	{
		$this->fieldStr = "";
		for ($y = 0; $y < 10; $y++)
		{
			for ($x = 0; $x < 10; $x++)
			{
				$val = $this->field[$y][$x];
				if (strcmp($val, $this->fox) != 0)
				{
					$count = 0;
					for ($y1 = 0; $y1 < 10; $y1++)
					{
						for ($x1 = 0; $x1 < 10; $x1++)
						{
							if ($x == $x1 && $y == $y1)
								continue;
							if (($x == $x1 || $y == $y1 || $x + $y == $x1 + $y1 || $x - $y == $x1 - $y1) && strcmp($this->field[$y1][$x1], $this->fox) == 0)
								$count++;
						}
					}
					$val = $count."";
					$this->field[$y][$x] = $val;
				}
				$coord = $this->letters[$x].$this->numbers[$y];
				$this->fieldByCoord[$coord] = $val;
				$this->fieldStr = $this->fieldStr.$val;
			}
		}
	}
	
	function GetCoordX($x)
	{
		return $this->letters[$x];
	}

	function GetCoordY($y)
	{
		return $this->numbers[$y];
	}

	function Generate()
	{
		$this->Clear();
		for ($i = 0; $i < $this->foxesTotal; $i++)
		{
			$x = random_int(0, 9);
			$y = random_int(0, 9);
			if (strcmp($this->field[$y][$x], $this->fox) != 0)
				$this->field[$y][$x] = $this->fox;
			else
				$i--;
		}
		$this->CalculateField();
	}
	
	function SetFieldWithFoxes($str)
	{
		$this->Clear();
		for ($i = 0; $i < $this->foxesTotal; $i++)
		{
			$x = intval(substr($str, $i * 2 + 0, 1));
			$y = intval(substr($str, $i * 2 + 1, 1));
			$this->field[$y][$x] = $this->fox;
		}
		$this->CalculateField();
		return true;
	}
	
	function SetField8($text)
	{
		$text = mb_strtoupper($text);
		$coords = explode("  ", $text);
		if (count($coords) != FOXES_TOTAL)
		$coords = explode(" ", $text);
		if (count($coords) != FOXES_TOTAL)
			return false;
		$str = "";
		for ($i = 0; $i < FOXES_TOTAL; $i++)
		{
			$coord = $coords[$i];
			if (isset($this->fieldByCoord[$coord]))
			{
				$x = array_search(mb_substr($coord, 0, 1), LETTERS);
				$y = array_search(mb_substr($coord, 1), NUMBERS);
				if ($x !== false && $y !== false)
					$str .= $x .$y;
				else 
					return false; //"На строке " . $y . " не найдены 10 символов, разделенных пробелом";
			}
		}
		if (strlen($str) != $this->foxesTotal * 2)
			return $str;
		return $this->SetFieldWithFoxes($str);
	}

	function SetField10($text)
	{
		$lines = explode("\n\r", $text);
		if (count($lines) != 10)
		$lines = explode("\n", $text);
		if (count($lines) != 10)
			return false;
		$str = "";
		for ($y = 0; $y < 10; $y++)
		{
			$line = $lines[$y];
			$parts = explode("\t", $line);
			if (count($parts) != 10)
				$parts = explode("  ", $line);
			if (count($parts) != 10)
				$parts = explode(" ", $line);
			if (count($parts) != 10)
				return false;
				//return "На строке " . $y . " не найдены 10 символов, разделенных пробелом";
			for ($x = 0; $x < 10; $x++)
			{
				$z = $parts[$x];
				if ($z == $this->fox || $z == $this->foxToShow || $z == "х"|| $z == "Х"|| $z == "x"|| $z == "X")
					$str .= $x .$y;
			}
		}
		if (strlen($str) != $this->foxesTotal * 2)
			return $str;
		return $this->SetFieldWithFoxes($str);
	}

	function SetField($user, $str)
	{
		$query = "SELECT * FROM ".$this->usersTable." WHERE user = ?";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(1, $user);
		$stmt->execute();
        $num = $stmt->rowCount();
        if($num == 0)
			return 0;
		
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->id = $row['game'];
		if (!$this->id)
			return 0;
		$withField = $row['field'] != "";
		
		$query = "SELECT * FROM ".$this->gamesTable." WHERE id = ?";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(1, $this->id);
		$stmt->execute();
        $num = $stmt->rowCount();
        if($num == 0)
			return 0;
		
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$usersStr = $row['users'];
		$this->users = $usersStr == "" ? array() : explode(" ", $usersStr);
		$this->chat_id = $row['chat_id'];
		$this->period = $row['period'];
		$user2 = $this->users[0] == $user ? $this->users[1] : $this->users[0];
		
		$this->SetFieldWithFoxes($str);
		
		$query = "UPDATE ".$this->usersTable." SET field = :field WHERE user = :user2";
		$stmt = $this->conn->prepare($query);
        $stmt->bindParam(':field', $this->fieldStr);
        $stmt->bindParam(':user2', $user2);
		$stmt->execute();
		
		$fields = 1 + $withField;
		if ($fields == 2)
		{
			$query = "UPDATE ".$this->gamesTable." SET state = 'started', lastTime = ".time()." WHERE id = ?";
			$stmt = $this->conn->prepare($query);
			$stmt->bindParam(1, $this->id);
			$stmt->execute();
			if ($stmt)
				return $fields;
		}

		return $fields;;
	}

    function Load($user){

        $query = "SELECT * FROM ".$this->usersTable." WHERE user = ?";

        $stmt = $this->conn->prepare( $query );
        $stmt->bindParam(1, $user);
        $stmt->execute();

        $num = $stmt->rowCount();
        if($num>0) {

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->id = $row['game'];
			$this->user = $user;
            $this->fieldStr = $row['field'];
            $this->turnsStr = $row['turns'];
			
			$query = "SELECT * FROM ".$this->gamesTable." WHERE id = ?";
			$stmt = $this->conn->prepare( $query );
			$stmt->bindParam(1, $this->id);
			$stmt->execute();
			
			$num = $stmt->rowCount();
			if($num>0) 
			{
				$rowGame = $stmt->fetch(PDO::FETCH_ASSOC);
				$this->kind = $rowGame["kind"];
				$this->state = $rowGame["state"];
				$this->turn = $rowGame["turn"];
				$this->chat_id = $rowGame["chat_id"];
				$usersStr = $rowGame["users"];
				$this->users = $usersStr == "" ? array() : explode(" ", $usersStr);
				$this->period = $rowGame["period"];
				$this->lastTime = $rowGame["lastTime"];
				
				$this->Deserialize();
				
				return true;
			}
        }

        return false;
    }
	
	function Deserialize()
	{
		$this->field = array();
		$this->fieldByCoord = array();
		$index = 0;
		for ($y = 0; $y < 10; $y++)
		{
			$arrIn = array();
			for ($x = 0; $x < 10; $x++)
			{
				$coord = $this->letters[$x].$this->numbers[$y];
				$val = substr($this->fieldStr, $index, 1);
				$arrIn[] = $val;
				$this->fieldByCoord[$coord] = $val;
				$index++;
			}
			$this->field[]=$arrIn;
		}
		
		$this->turns = $this->turnsStr == "" ? array() : explode(" ", $this->turnsStr);
		for ($i = 0; $i < count($this->turns); $i++)
			if ($this->fieldByCoord[$this->turns[$i]]==$this->fox)
				$this->foxesCount++;

	}
	
	function ProcessTurn($coord)
	{
		$coord = mb_strtoupper($coord);
		$val = $this->GetCellByCoord($coord);
		if ($val != "")
		{
			if (!in_array($coord, $this->turns))
			{
				$this->turns[] = $coord;
				$this->turn++;
				if ($val == $this->fox)
				{
					$this->foxesCount++;
					$this->turn++;
				}
			}
			return $val == $this->fox ? $this->foxToShow : $val;
		}
		else 
			return "";
	}
	
	function GetCellByCoord($coord)
	{
		if (isset($this->fieldByCoord[$coord]))
			return $this->fieldByCoord[$coord];
		else
			return "";
	}
	
	function SetPeriod($period)
	{
        $query = "UPDATE
                " . $this->gamesTable . "
            SET
                period = :period,
				lastTime = :time
            WHERE
                id = :id";

        $stmt = $this->conn->prepare($query);
		$time = time();
        $stmt->bindParam(':period', $period);
        $stmt->bindParam(':time', $time);
        $stmt->bindParam(':id', $this->id);

		if ($stmt->execute())
		{
			$this->period = $period;
			$this->lastTime = $time;
			return true;
		}
		else
			return false;
	}

    function Save()
	{
		$this->turnsStr = implode(" ", $this->turns);

        $query = "UPDATE
                " . $this->usersTable . "
            SET
                field = :field,
                turns = :turns
            WHERE
                user = :user";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':user', $this->user);
        $stmt->bindParam(':field', $this->fieldStr);
        $stmt->bindParam(':turns', $this->turnsStr);

        if ($stmt->execute()) {

			$query = "UPDATE
					" . $this->gamesTable . "
				SET
					turn = :turn,
					lastTime = :time
				WHERE
					id = :id";
			$time = time();
			$stmt = $this->conn->prepare($query);

			$stmt->bindParam(':turn', $this->turn);
			$stmt->bindParam(':time', $time);
			$stmt->bindParam(':id', $this->id);

			if ($stmt->execute())
				return true;
        }

        return $stmt->errorInfo()[2];
    }

    function Remove($user){

  		$query = "SELECT * FROM ".$this->usersTable." WHERE user = ?";
		$stmt = $this->conn->prepare($query);
		$stmt->bindParam(1, $user);
		$stmt->execute();
        $num = $stmt->rowCount();
		if ($num == 0)
			return false;
		
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->id = $row['game'];

		$query = "DELETE FROM `".$this->gamesTable."` WHERE id = ?";
        $stmt = $this->conn->prepare( $query );
        $stmt->bindParam(1, $this->id);
        $stmt->execute();

		$query = "UPDATE ".$this->usersTable." SET game = 0, field = '', turns = '' WHERE game = :game";
		$stmt = $this->conn->prepare($query);
        $stmt->bindParam(':game', $this->id);
		$stmt->execute();

        if ($stmt) {
            return true;
        } else {
            return false;
        }

    }
	
	function ToString()
	{
		return $this->GetField(true);
	}

	function GetBattle()
	{
		return $this->GetField(false);
	}

	function GetField($all)
	{
		if (!isset($this->fieldStr) || $this->fieldStr == null || strlen($this->fieldStr) == 0)
			return "(поле не задано)";
		$str = "   ";
		for ($x = 0; $x < 10; $x++)
			$str .= $this->letters[$x] . " ";
		$str .= "\n";
		for ($y = 0; $y < 10; $y++)
		{
			$num = $this->numbers[$y] . "";
			$str .= $num . (strlen($num) == 1 ? " " : "") . " ";
			for ($x = 0; $x < 10; $x++)
			{
				$coord = $this->letters[$x].$this->numbers[$y];
				$str .= ($all || in_array($coord, $this->turns) ? ($this->fieldByCoord[$coord] == $this->fox ? $this->foxToShow : $this->fieldByCoord[$coord]) : "·") . " ";
			}
			$str .= "\n";
		}
		return $str;
	}
	
	function GetFoxesCoords()
	{
		$str = "";
		for ($y = 0; $y < 10; $y++)
		{
			for ($x = 0; $x < 10; $x++)
			{
				if ($this->field[$y][$x] == $this->fox)
					$str .= $x . $y;
			}
		}
		return $str;
	}
	
	function GetStats()
	{
		return "Сделано ходов: " . ($this->turns ? count($this->turns) : 0) . ". Найдено лис: " . $this->foxesCount . "/".($this->foxesTotal).".\n";	
	}
	
}
?>