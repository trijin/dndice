<?php

/**
 * Dice Rolling Library for PHP 5.6
 * Парсит и обрабатывает формулы с кубиками в тексте
 */
class DnDice
{
    private $formulas = array();
    private $paramStore = null;

    /**
     * Конструктор
     * @param ParamStoreInterface $paramStore Объект с методом get($param)
     */
    public function __construct(ParamStoreInterface $paramStore = null)
    {
        $this->paramStore = $paramStore;
    }

    /**
     * Основная функция обработки текста
     * @param string $text Входящий текст с формулами
     * @return array Массив результатов
     */
    public function processText($text)
    {
        $this->formulas = array();

        // Регулярное выражение для поиска формул
        $pattern = '/(?:^|[^a-zA-Z0-9])([sf]?\s*(?:\d*d\d+(?:[hlkmdr!x][\w\d]*)*(?:\s*[+\-*\/]\s*(?:\d+|\d*d\d+(?:[hlkmdr!x][\w\d]*)*|\([^)]+\)))*(?:\s*[><]\s*\d+)?(?:\s*[sc][><]\d+)?(?:\s*&\w+)*|\([^)]+\)))(?:[^a-zA-Z0-9]|$)/i';

        preg_match_all($pattern, $text, $matches);

        $results = array();

        foreach ($matches[1] as $formula) {
            $formula = trim($formula);
            if (!empty($formula)) {
                $result = $this->processFormula($formula);
                if ($result !== null) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * Обработка одной формулы
     * @param string $formula
     * @return array|null
     */
    private function processFormula($formula)
    {
        $originalFormula = $formula;

        // Извлекаем префиксы
        $spoiler = false;
        $showDetails = false;

        if (preg_match('/^s\s*(.+)/', $formula, $match)) {
            $spoiler = true;
            $formula = $match[1];
        }

        if (preg_match('/^f\s*(.+)/', $formula, $match)) {
            $showDetails = true;
            $formula = $match[1];
        }

        // Заменяем параметры
        $formula = $this->replaceParameters($formula);

        try {
            $ast = $this->parseFormula($formula);
            $result = $this->evaluateAST($ast);

            return array(
                'original' => $originalFormula,
                'expanded' => $result['detailed'],
                'result' => $result['value'],
                'modifiers' => $result['modifiers'],
                'spoiler' => $spoiler,
                'showDetails' => $showDetails
            );
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Замена параметров &paramName с поддержкой рекурсии
     */
    private function replaceParameters($formula)
    {
        $processedParams = array();
        return $this->replaceParametersRecursive($formula, $processedParams);
    }

    private function replaceParametersRecursive($formula, &$processedParams)
    {
        $result = preg_replace_callback('/&(\w+)/', array($this, 'replaceParameterCallback'), $formula);

        // Проверяем, есть ли еще параметры для замены
        if ($result !== $formula && preg_match('/&\w+/', $result)) {
            // Есть параметры, нужна рекурсия
            // Но сначала проверим на циклические ссылки
            $newParams = array();
            preg_match_all('/&(\w+)/', $result, $matches);

            foreach ($matches[1] as $param) {
                if (in_array($param, $processedParams)) {
                    // Циклическая ссылка, заменяем на пустую строку
                    $result = str_replace('&' . $param, '', $result);
                } else {
                    $newParams[] = $param;
                }
            }

            if (!empty($newParams)) {
                $allProcessed = array_merge($processedParams, $newParams);
                return $this->replaceParametersRecursive($result, $allProcessed);
            }
        }

        return $result;
    }

    private function replaceParameterCallback($matches)
    {
        $paramName = $matches[1];
        if ($this->paramStore !== null && method_exists($this->paramStore, 'get')) {
            return $this->paramStore->get($paramName);
        }
        return '';
    }

    /**
     * Парсинг формулы в AST
     */
    private function parseFormula($formula)
    {
        return $this->parseExpression($formula);
    }

    /**
     * Парсинг выражения с учетом приоритетов
     */
    private function parseExpression($formula)
    {
        $formula = trim($formula);

        // Логические операторы (самый низкий приоритет)
        if (preg_match('/^(.+?)\s*([><])\s*(.+)$/', $formula, $match)) {
            return array(
                'type' => 'comparison',
                'left' => $this->parseExpression($match[1]),
                'operator' => $match[2],
                'right' => $this->parseExpression($match[3])
            );
        }

        // Сумма или количество операторы
        if (preg_match('/^([sc])([><])(\d+)$/', $formula, $match)) {
            return array(
                'type' => 'dice_comparison',
                'mode' => $match[1], // s = sum, c = count
                'operator' => $match[2],
                'target' => intval($match[3])
            );
        }

        // Сложение и вычитание
        if (preg_match('/^(.+?)\s*([+\-])\s*(.+)$/', $formula, $match)) {
            return array(
                'type' => 'binary_op',
                'left' => $this->parseExpression($match[1]),
                'operator' => $match[2],
                'right' => $this->parseExpression($match[3])
            );
        }

        // Умножение и деление
        if (preg_match('/^(.+?)\s*([*\/])\s*(.+)$/', $formula, $match)) {
            return array(
                'type' => 'binary_op',
                'left' => $this->parseExpression($match[1]),
                'operator' => $match[2],
                'right' => $this->parseExpression($match[3])
            );
        }

        // Скобки
        if (preg_match('/^\((.+)\)$/', $formula, $match)) {
            return $this->parseExpression($match[1]);
        }

        // Кубики
        if (preg_match('/^(\d*)d(\d+)(.*)$/', $formula, $match)) {
            $count = empty($match[1]) ? 1 : intval($match[1]);
            $sides = intval($match[2]);
            $modifiers = $match[3];

            return array(
                'type' => 'dice',
                'count' => $count,
                'sides' => $sides,
                'modifiers' => $this->parseModifiers($modifiers)
            );
        }

        // Число
        if (is_numeric($formula)) {
            return array(
                'type' => 'number',
                'value' => intval($formula)
            );
        }

        throw new Exception("Не удалось распарсить: " . $formula);
    }

    /**
     * Парсинг модификаторов кубика
     */
    private function parseModifiers($modString)
    {
        $modifiers = array();
        $modString = trim($modString);

        // Парсим модификаторы по очереди
        while (!empty($modString)) {
            $matched = false;

            // Keep highest/lowest
            if (preg_match('/^(k?[hl])(\d*)(.*)$/', $modString, $match)) {
                $type = $match[1];
                $value = empty($match[2]) ? 1 : intval($match[2]);
                $modifiers[] = array('type' => $type, 'value' => $value);
                $modString = $match[3];
                $matched = true;
            }
            // Keep min/max
            elseif (preg_match('/^(km)(\d*)(.*)$/', $modString, $match)) {
                $value = empty($match[2]) ? 1 : intval($match[2]);
                $modifiers[] = array('type' => 'km', 'value' => $value);
                $modString = $match[3];
                $matched = true;
            }
            // Drop highest/lowest
            elseif (preg_match('/^(d[hl])(\d*)(.*)$/', $modString, $match)) {
                $type = $match[1];
                $value = empty($match[2]) ? 1 : intval($match[2]);
                $modifiers[] = array('type' => $type, 'value' => $value);
                $modString = $match[3];
                $matched = true;
            }
            // Drop min/max
            elseif (preg_match('/^(dm)(\d*)(.*)$/', $modString, $match)) {
                $value = empty($match[2]) ? 1 : intval($match[2]);
                $modifiers[] = array('type' => 'dm', 'value' => $value);
                $modString = $match[3];
                $matched = true;
            }
            // Reroll
            elseif (preg_match('/^r(\d+)(.*)$/', $modString, $match)) {
                $modifiers[] = array('type' => 'r', 'value' => intval($match[1]));
                $modString = $match[2];
                $matched = true;
            }
            // Reroll once
            elseif (preg_match('/^ro(\d+)(.*)$/', $modString, $match)) {
                $modifiers[] = array('type' => 'ro', 'value' => intval($match[1]));
                $modString = $match[2];
                $matched = true;
            }
            // Exploding dice
            elseif (preg_match('/^([!x])(\d*)(.*)$/', $modString, $match)) {
                $limit = empty($match[2]) ? 999 : intval($match[2]);
                $modifiers[] = array('type' => 'explode', 'limit' => $limit);
                $modString = $match[3];
                $matched = true;
            }

            if (!$matched) {
                break;
            }
        }

        return $modifiers;
    }

    /**
     * Выполнение AST
     */
    private function evaluateAST($ast)
    {
        switch ($ast['type']) {
            case 'number':
                return array(
                    'value' => $ast['value'],
                    'detailed' => strval($ast['value']),
                    'modifiers' => array()
                );

            case 'dice':
                return $this->rollDice($ast);

            case 'binary_op':
                $left = $this->evaluateAST($ast['left']);
                $right = $this->evaluateAST($ast['right']);

                $result = $this->applyBinaryOperator($left['value'], $ast['operator'], $right['value']);

                return array(
                    'value' => $result,
                    'detailed' => $left['detailed'] . ' ' . $ast['operator'] . ' ' . $right['detailed'] . ' = ' . $result,
                    'modifiers' => array_merge($left['modifiers'], $right['modifiers'])
                );

            case 'comparison':
                $left = $this->evaluateAST($ast['left']);
                $right = $this->evaluateAST($ast['right']);

                $success = $this->applyComparison($left['value'], $ast['operator'], $right['value']);

                return array(
                    'value' => $success ? 'Success' : 'Fail',
                    'detailed' => $left['detailed'] . ' ' . $ast['operator'] . ' ' . $right['detailed'] . ' = ' . ($success ? 'Success' : 'Fail'),
                    'modifiers' => array_merge($left['modifiers'], $right['modifiers'])
                );

            case 'dice_comparison':
                // Для s> и c> нужно получить кубики из контекста
                return array(
                    'value' => 'NotImplemented',
                    'detailed' => 'dice_comparison',
                    'modifiers' => array()
                );
        }

        return array('value' => 0, 'detailed' => '', 'modifiers' => array());
    }

    /**
     * Бросок кубиков с модификаторами
     */
    private function rollDice($diceAST)
    {
        $count = $diceAST['count'];
        $sides = $diceAST['sides'];
        $modifiers = $diceAST['modifiers'];

        // Базовый бросок
        $rolls = array();
        for ($i = 0; $i < $count; $i++) {
            $rolls[] = mt_rand(1, $sides);
        }

        $originalRolls = $rolls;
        $detailed = $count . 'd' . $sides . ' [' . implode(', ', $rolls) . ']';

        // Применяем модификаторы
        foreach ($modifiers as $modifier) {
            $rolls = $this->applyModifier($rolls, $modifier, $sides);
        }

        $sum = array_sum($rolls);

        if ($rolls !== $originalRolls) {
            $detailed .= ' -> [' . implode(', ', $rolls) . '] = ' . $sum;
        } else {
            $detailed .= ' = ' . $sum;
        }

        return array(
            'value' => $sum,
            'detailed' => $detailed,
            'modifiers' => $modifiers
        );
    }

    /**
     * Применение модификатора к броскам
     */
    private function applyModifier($rolls, $modifier, $sides)
    {
        switch ($modifier['type']) {
            case 'h':
            case 'kh':
                rsort($rolls);
                return array_slice($rolls, 0, $modifier['value']);

            case 'l':
            case 'kl':
                sort($rolls);
                return array_slice($rolls, 0, $modifier['value']);

            case 'km':
                sort($rolls);
                $keep = $modifier['value'] * 2;
                $result = array();
                for ($i = 0; $i < $modifier['value']; $i++) {
                    $result[] = $rolls[$i]; // min
                    $result[] = $rolls[count($rolls) - 1 - $i]; // max
                }
                return $result;

            case 'dh':
                rsort($rolls);
                return array_slice($rolls, $modifier['value']);

            case 'dl':
                sort($rolls);
                return array_slice($rolls, $modifier['value']);

            case 'dm':
                sort($rolls);
                $drop = $modifier['value'];
                return array_slice($rolls, $drop, count($rolls) - $drop * 2);

            case 'r':
                for ($i = 0; $i < count($rolls); $i++) {
                    while ($rolls[$i] < $modifier['value']) {
                        $rolls[$i] = mt_rand(1, $sides);
                    }
                }
                return $rolls;

            case 'ro':
                for ($i = 0; $i < count($rolls); $i++) {
                    if ($rolls[$i] < $modifier['value']) {
                        $newRoll = mt_rand(1, $sides);
                        $rolls[$i] = max($rolls[$i], $newRoll);
                    }
                }
                return $rolls;

            case 'explode':
                $newRolls = array();
                foreach ($rolls as $roll) {
                    $newRolls[] = $roll;
                    $explosions = 0;
                    $currentRoll = $roll;

                    while ($currentRoll == $sides && $explosions < $modifier['limit']) {
                        $currentRoll = mt_rand(1, $sides);
                        $newRolls[] = $currentRoll;
                        $explosions++;
                    }
                }
                return $newRolls;
        }

        return $rolls;
    }

    /**
     * Применение бинарного оператора
     */
    private function applyBinaryOperator($left, $operator, $right)
    {
        switch ($operator) {
            case '+': return $left + $right;
            case '-': return $left - $right;
            case '*': return $left * $right;
            case '/': return $right != 0 ? intval($left / $right) : 0;
        }
        return 0;
    }

    /**
     * Применение оператора сравнения
     */
    private function applyComparison($left, $operator, $right)
    {
        switch ($operator) {
            case '>': return $left > $right;
            case '<': return $left < $right;
        }
        return false;
    }
}

// Пример использования

// Простое хранилище параметров
class SimpleParamStore
{
    private $params = array();

    public function __construct($params = array())
    {
        $this->params = $params;
    }

    public function get($param)
    {
        return isset($this->params[$param]) ? $this->params[$param] : '';
    }

    public function set($param, $value)
    {
        $this->params[$param] = $value;
    }
}

/*
// Пример с рекурсивными параметрами
$store = new SimpleParamStore(array(
    'weapon' => '1d8+&strength',
    'strength' => '&str_mod',
    'str_mod' => '3',
    'attack' => '1d20+&weapon',
    'circular' => '&circular2',
    'circular2' => '&circular'
));

$roller = new DiceRoller($store);

$text = "Атакую &attack урон &weapon";

$results = $roller->processText($text);

foreach ($results as $result) {
    echo "Формула: " . $result['original'] . "\n";
    echo "Расчет: " . $result['expanded'] . "\n";
    echo "Результат: " . $result['result'] . "\n";
    echo "Модификаторы: " . print_r($result['modifiers'], true) . "\n";
    echo "---\n";
}
*/

?>