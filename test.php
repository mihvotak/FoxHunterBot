<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

require_once("core.php");
require_once("database.php");
require_once("game.php");

$database = new Database();
$db = $database->getConnection();

$user = "mihvotak";
$user2 = "mihvotak2";
$game = new Game($db);
echo "<html>";
echo "CreateNew: " . $game->CreateNewSolo($user);
$game->user = $user;
$game->Generate();
echo "<pre>" . $game->ToString() . "</pre><br />";
$foxes = $game->GetFoxesCoords();
echo "Foxes: " . $foxes . "<br />";
echo "Save: " . $game->Save() . "<br />";
/*
header("Content-type: image/png");
$string = "123";
$im     = imagecreatefrompng("images/img.png");
$orange = imagecolorallocate($im, 220, 210, 60);
$px     = (imagesx($im) - 7.5 * strlen($string)) / 2;
imagestring($im, 3, $px, 9, $string, $orange);
imagepng($im);
imagedestroy($im);
*/
$im = new Imagick ();
$size = 300;
$step = 25;;
$start = 40;
$im->newImage ($size, $size, "white");
$im->borderImage("black", 1, 1);

$bg = new ImagickDraw();
$draw = new ImagickDraw();
$draw->setFillColor('black');
//$draw->setFont('Courier');
$draw->setFontSize(24);

	$strokeColor = new \ImagickPixel("gray");
    $bg->setStrokeColor($strokeColor);
    //$bg->setStrokeOpacity(1);
    $bg->setStrokeWidth(1);
	
for ($y = 0; $y < 10; $y ++)
{
	for ($x = 0; $x < 10; $x ++)
	{
		$xx = $start + $x * $step;
		$yy = $start + $y * $step;
		$color = '#ffffff';
		$val = $game->field[$y][$x];
		if ($val == $game->fox)
			$color = "#88ffff";
		else if ($val == "1")
			$color = "#88ffaa";
		else if ($val == "2")
			$color = "#aaff88";
		else if ($val == "3")
			$color = "#ffff44";
		else if ($val == "4")
			$color = "#eebb88";
		else if ($val >= "5")
			$color = "#ff8888";
		$bg->setFillColor($color);
		$bg->rectangle($xx, $yy, $xx + $step, $yy + $step);
		$im->drawImage($bg);
	}
}
for ($x = 0; $x < 10; $x ++)
{
	$xx = $start + $x * $step + 6;
	$yy = $start - 6;
	$im->annotateImage($draw, $xx, $yy, 0, $game->GetCoordX($x));
}
for ($y = 0; $y < 10; $y ++)
{
	$xx = $start - $step + 2 - ($y == 10-1 ? 12 : 0);
	$yy = $start + $y * $step + $step - 2;
	$im->annotateImage($draw, $xx, $yy, 0, $game->GetCoordY($y));
}
for ($y = 0; $y < 10; $y ++)
{
	for ($x = 0; $x < 10; $x ++)
	{
		$val = $game->field[$y][$x];
		$xx = $start + $x * $step + 6;
		$yy = $start + $y * $step + $step - 2;
		if ($val == $game->fox)
		{
			$val = $game->foxToShow;
			$xx -= 5;
		}
		$im->annotateImage($draw, $xx, $yy, 0, $val . "");
	}
}

echo $im->writeImage ("images/test_1.png");
echo "<img src='images/test_1.png' />";
?>
