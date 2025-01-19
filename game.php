<?php
declare(strict_types=1);

require 'vendor/autoload.php';

class Dice {
    private $numbers;
    
    public function __construct($numbers) {
        if (count($numbers) != 6) {
            throw new InvalidArgumentException("Need 6 numbers for dice");
        }
        $this->numbers = $numbers;
    }
    
    public function getNumbers() {
        return $this->numbers;
    }
    
    public function __toString() {
        return implode(',', $this->numbers);
    }
}

class DiceParser {
    public function parse($input) {
        if (count($input) < 3) {
            throw new InvalidArgumentException(
                "Need 3 or more dice\n" .
                "Example: php game.php 2,2,4,4,9,9 6,8,1,1,8,6 7,5,3,7,5,3"
            );
        }

        $allDice = [];
        
        foreach ($input as $diceInput) {
            $numbers = explode(',', $diceInput);
            
            foreach ($numbers as $num) {
                if (!is_numeric($num)) {
                    throw new InvalidArgumentException("Invalid number: $num in $diceInput");
                }
            }
            
            $numbers = array_map('intval', $numbers);
            
            $allDice[] = new Dice($numbers);
        }

        return $allDice;
    }
}

class CryptoProvider {
    const HASH = 'sha3-256';
    const KEY_LENGTH = 32;

    public static function makeKey() {
        return bin2hex(random_bytes(self::KEY_LENGTH));
    }

    public static function makeHash($key, $number) {
        $binKey = hex2bin($key);
        if (!$binKey) {
            throw new RuntimeException('Bad key');
        }
        return strtoupper(hash_hmac(self::HASH, (string)$number, $binKey));
    }

    public static function getRandom($max) {
        if ($max < 0) {
            throw new InvalidArgumentException('Max must be >= 0');
        }

        $bits = strlen(decbin($max));
        $bytes = (int)ceil($bits / 8);
        $mask = (1 << $bits) - 1;

        while (true) {
            $random = random_bytes($bytes);
            $value = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $value = ($value << 8) | ord($random[$i]);
            }
            $value = $value & $mask;
            
            if ($value <= $max) {
                return $value;
            }
        }
    }

    public static function checkHash($key, $number, $hash) {
        return hash_equals(self::makeHash($key, $number), $hash);
    }
}

class NumberGenerator {
    private $crypto;

    public function __construct() {
        $this->crypto = new CryptoProvider();
    }

    public function getNumber($max) {
        $key = CryptoProvider::makeKey();
        $num = CryptoProvider::getRandom($max);
        $hash = CryptoProvider::makeHash($key, $num);
        
        return [$num, $key, $hash];
    }

    public function addNumbers($num1, $num2, $mod) {
        return ($num1 + $num2) % $mod;
    }

    public function checkNumber($num, $key, $hash) {
        return CryptoProvider::checkHash($key, $num, $hash);
    }
}

class WinCalculator {
    public static function calcWinChance($dice1, $dice2) {
        $wins = 0;
        $total = 36;

        foreach ($dice1->getNumbers() as $num1) {
            foreach ($dice2->getNumbers() as $num2) {
                if ($num1 > $num2) {
                    $wins++;
                }
            }
        }

        return $wins / $total;
    }
}

class TableMaker {
    public static function makeTable($allDice) {
        $table = new \Console_Table(CONSOLE_TABLE_ALIGN_LEFT, '|', 1);
        
        $headers = ['User dice v'];
        foreach ($allDice as $dice) {
            $headers[] = (string)$dice;
        }
        $table->setHeaders($headers);
        
        foreach ($allDice as $i => $dice1) {
            $row = [(string)$dice1];
            foreach ($allDice as $j => $dice2) {
                if ($i == $j) {
                    $prob = WinCalculator::calcWinChance($dice1, $dice2);
                    $row[] = sprintf("- (%.4f)", $prob);
                } else {
                    $prob = WinCalculator::calcWinChance($dice1, $dice2);
                    $row[] = sprintf("%.4f", $prob);
                }
            }
            $table->addRow($row);
        }

        $output = "\nProbability of winning for the user:\n";
        $output .= "Each cell shows the probability of the dice in the row winning against the dice in the column.\n";
        $output .= "Diagonal entries show probabilities against itself (marked with '-').\n\n";
        $output .= $table->getTable();
        
        return $output;
    }
}

class GameController {
    private $allDice;
    private $generator;
    private $debug;

    public function __construct($dice, $debug = false) {
        $this->allDice = $dice;
        $this->generator = new NumberGenerator();
        $this->debug = $debug;
    }

    public function startGame() {
        try {
            $computerFirst = $this->decideFirstPlayer();
            
            if ($computerFirst) {
                echo "I go first.\n";
                $computerDice = $this->computerPickDice();
                $leftDice = array_filter($this->allDice, function($d) use ($computerDice) {
                    return $d !== $computerDice;
                });
                $playerDice = $this->playerPickDice(array_values($leftDice));
            } else {
                echo "You go first.\n";
                $playerDice = $this->playerPickDice($this->allDice);
                $leftDice = array_filter($this->allDice, function($d) use ($playerDice) {
                    return $d !== $playerDice;
                });
                $computerDice = $this->computerPickDice(array_values($leftDice));
            }

            $computerRoll = $this->doComputerRoll();
            $playerRoll = $this->doPlayerRoll();

            $this->showResult($computerRoll, $playerRoll);
            
        } catch (Exception $e) {
            if ($this->debug) {
                throw $e;
            }
            echo "Error in game: " . $e->getMessage() . "\n";
        }
    }

    private function decideFirstPlayer() {
        echo "Let's decide who goes first.\n";
        list($num, $key, $hash) = $this->generator->getNumber(1);
        echo "I picked 0 or 1 (HASH=$hash).\n";

        while (true) {
            echo "What's your guess?\n";
            echo "0 - zero\n";
            echo "1 - one\n";
            echo "X - quit\n";
            echo "? - help\n";

            $choice = trim(fgets(STDIN));

            if ($choice == 'X') {
                exit(0);
            }
            if ($choice == '?') {
                $this->showHelp();
                continue;
            }
            if ($choice != '0' && $choice != '1') {
                echo "Pick 0 or 1.\n";
                continue;
            }

            $playerNum = (int)$choice;
            echo "I picked: $num (KEY=$key)\n";
            
            return ($num + $playerNum) % 2 == 1;
        }
    }

    private function computerPickDice($available = null) {
        $choices = $available ?? $this->allDice;
        
        $picked = $choices[CryptoProvider::getRandom(count($choices) - 1)];
        echo "I pick [$picked].\n";
        return $picked;
    }

    private function playerPickDice($choices) {
        while (true) {
            echo "Pick your dice:\n";
            
            for ($i = 0; $i < count($choices); $i++) {
                echo "$i - {$choices[$i]}\n";
            }
            echo "X - quit\n";
            echo "? - help\n";

            $choice = trim(fgets(STDIN));

            if ($choice == 'X') {
                exit(0);
            }
            if ($choice == '?') {
                $this->showHelp();
                continue;
            }

            if (is_numeric($choice) && isset($choices[(int)$choice])) {
                $picked = $choices[(int)$choice];
                echo "You picked [$picked].\n";
                return $picked;
            }

            echo "Pick a valid number.\n";
        }
    }

    private function doComputerRoll() {
        echo "\nMy turn to roll.\n";
        
        list($num, $key, $hash) = $this->generator->getNumber(5);
        echo "HMAC of my choice: $hash\n";
        
        $playerNum = $this->getUserNumber();
        
        echo "My number was: $num (KEY=$key)\n";
        $result = $this->generator->addNumbers($num, $playerNum, 6);
        echo "Final result: ($num + $playerNum) % 6 = $result\n";
        
        return $result;
    }

    private function doPlayerRoll() {
        echo "\nYour turn to roll.\n";
        
        list($num, $key, $hash) = $this->generator->getNumber(5);
        echo "HMAC of my choice: $hash\n";
        
        $playerNum = $this->getUserNumber();
        
        echo "My number was: $num (KEY=$key)\n";
        $result = $this->generator->addNumbers($num, $playerNum, 6);
        echo "Final result: ($num + $playerNum) % 6 = $result\n";
        
        return $result;
    }

    private function getUserNumber() {
        while (true) {
            echo "Choose your number (0-5):\n";
            for ($i = 0; $i < 6; $i++) {
                echo "$i - $i\n";
            }
            echo "X - quit\n";
            echo "? - help\n";
            
            $choice = trim(fgets(STDIN));
            
            if ($choice === 'X') {
                exit(0);
            }
            if ($choice === '?') {
                $this->showHelp();
                continue;
            }
            if (is_numeric($choice) && $choice >= 0 && $choice < 6) {
                return (int)$choice;
            }
            echo "Please choose a number between 0 and 5.\n";
        }
    }

    private function showResult($computer, $player) {
        echo "Computer rolled: $computer\n";
        echo "You rolled: $player\n";

        if ($computer > $player) {
            echo "I win!\n";
        } elseif ($player > $computer) {
            echo "You win!\n";
        } else {
            echo "It's a tie!\n";
        }
    }

    private function showHelp() {
        echo "\nGame Rules:\n";
        echo "1. Players take turns rolling dice\n";
        echo "2. Each roll is determined by combining computer and player numbers\n";
        echo "3. The player with the higher number wins\n";
        echo "4. Numbers wrap around using modulo 6\n\n";
        echo TableMaker::makeTable($this->allDice);
        echo "\n";
    }
}

try {
    if ($argc < 4) {
        throw new InvalidArgumentException(
            "Need 3 or more dice\n" .
            "Example: php game.php 2,2,4,4,9,9 6,8,1,1,8,6 7,5,3,7,5,3"
        );
    }

    $inputs = array_slice($argv, 1);
    
    $parser = new DiceParser();
    $dice = $parser->parse($inputs);
    
    $game = new GameController($dice);
    $game->startGame();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
