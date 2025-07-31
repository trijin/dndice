<?php

/**
 * Dice Rolling Library for PHP 5.6
 * Парсит и обрабатывает формулы с кубиками в тексте
 */

class DnDice
{
    private $formulas = array();
    private $paramStore = null;

    private $cachedParams = array();
    private $paramsCallCount = array('out'=>0,'in'=>0);

    const ORIGIN_regex = '/(?:^|[^a-zA-Z0-9])([sf]*\s*(?:\([^)]*\)|[\d&]\w*d\d+[\w\d&]*(?:\s*[+\-*\/]\s*(?:\d+|[\d&]\w*d\d+[\w\d&]*|\([^)]+\)))*)\s*(?:[sc]?[><]\s*\d+)?)(?:[^a-zA-Z0-9]|$)/i';
    const GPT_regex = '/
    s?\(.*?\)s[><=]?\d+                                  # s(...)s>35
    |s?\d*d\d+(?:[a-z]+\d*)*(?:&\w+)?(?:c[><=]?\d+)?     # s6d20kh4&str или s6d20x4c>10
    |\d*d\d+                                             # обычные d20, 3d10
    |&\w+                                                # &attack
    |s?\d*d\d+(?:[a-z]+\d*)*(?:&\w+)?(?:c[><=]?\d+)?\s*[><=]\s*\d*d\d+(?:[a-z]+\d*)*(?:&\w+)?(?:c[><=]?\d+)? # сравнение: формула > формула
/x';
    const MY_regex = '/(?:^|[^a-zA-Z0-9])((?:[sf]{1,2})?(?:{?&[&a-zA-Z0-9]+}?|d\d+|\d+d\d+|\()(?:{?&[&a-zA-Z0-9]+}?|d\d+|\d+d\d+|[()]|\s[<>]\s|(?:{?&[&a-zA-Z0-9]+}?|(?:h|[kd][hlm]\d+?)|!|x\d+?|[sc][<>]=?\d+|ro?\d+|\s?[+\-*\/]\s?\d+?))*)+(?:[^a-zA-Z0-9]|$)/';

    /**
     * Конструктор
     * @param ParamStoreInterface $paramStore Объект с методом get($param)
     */
    public function __construct(ParamStoreInterface $paramStore)
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

        // Улучшенное регулярное выражение для поиска формул
        $pattern = self::MY_regex;
        preg_match_all($pattern, $text, $matches);

        $results = array();

        foreach ($matches[1] as $formula)
        {
            $formula = trim($formula);
            if (!empty($formula))
            {
                $result = $this->processFormula($formula);
                if ($result !== null)
                {
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
        $expandedFormula = $this->replaceParameters($formula);
        $mainFormula = $expandedFormula;

        // Извлекаем префиксы (могут быть оба сразу)
        $spoiler = false;
        $showDetails = false;

        // Проверяем на комбинацию префиксов sf или fs
        if (preg_match('/^([sf]{1,2})\s*(.+)/', $expandedFormula, $match))
        {
            $prefixes = $match[1];
            $mainFormula = $match[2];

            if (strpos($prefixes, 's') !== false)
            {
                $spoiler = true;
            }
            if (strpos($prefixes, 'f') !== false)
            {
                $showDetails = true;
            }
        }

        try
        {
            $ast = $this->parseFormula($mainFormula);

            $this->validateAST($ast); // Валидация формулы
            $result = $this->evaluateAST($ast);

            return array(
                'original'    => $originalFormula,
                'formula'     => $expandedFormula, // Формула с раскрытыми параметрами
                'expanded'    => $result['detailed'],
                'result'      => $result['value'],
                'modifiers'   => $result['modifiers'],
                'spoiler'     => $spoiler,
                'showDetails' => $showDetails
            );
        }
        catch (Exception $e)
        {
            return null;
        }
    }

    /**
     * Валидация AST на корректность формулы
     */
    private function validateAST($ast)
    {
        switch ($ast['type'])
        {
            case 'dice_count':
                // c>X может применяться только к кубикам
                if ($ast['dice_expr']['type'] !== 'dice')
                {
                    throw new Exception("Модификатор c>X может применяться только к броску кубиков");
                }
                break;

            case 'comparison':
            case 'binary_op':
                $this->validateAST($ast['left']);
                $this->validateAST($ast['right']);
                break;

            case 'dice_comparison':
                $this->validateAST($ast['dice_expr']);
                break;
        }
    }

    /**
     * Замена параметров &paramName с поддержкой рекурсии
     */
    private function replaceParameters($formula)
    {
        return $this->replaceParametersRecursive($formula);
    }

    private function replaceParametersRecursive($formula)
    {
        try
        {
            $result = preg_replace_callback('/{?&(\w+)}?/', array($this, 'replaceParameterCallback'), $formula);
        } catch (InvalidArgumentException $e) {
            $result = str_replace('&','', $formula);
        }

        // Проверяем, есть ли еще параметры для замены
        if ($result !== $formula && preg_match('/&\w+/', $result))
        {
            return $this->replaceParametersRecursive($result);
        }

        return $result;
    }

    private function replaceParameterCallback($matches)
    {
        if($this->paramsCallCount['out']>$this->paramsCallCount['in']*100) {
            // Рекурсивная проблема.
            throw new InvalidArgumentException('Слишком много вложеностей.');
        }
        $paramName = $matches[1];
        if(!array_key_exists($paramName,$this->cachedParams )) {
            $this->cachedParams[$paramName]=array('out'=>0,'val'=>'');
            $this->paramsCallCount['in']++;
            $this->cachedParams[$paramName]['val'] = $this->paramStore->get($paramName);
            if(!is_string($this->cachedParams[$paramName]['val']))
                $this->cachedParams[$paramName]['val'] = '';
        }
        $this->paramsCallCount['out']++;
        $this->cachedParams[$paramName]['out'] = $this->cachedParams[$paramName]['out']?$this->cachedParams[$paramName]['out']+1:1;

        return $this->cachedParams[$paramName]['val'];
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

        // Сначала проверяем специальные операторы для кубиков (s>X) - глобальный уровень
        if (preg_match('/^(.+?)(s)([><])(\d+)$/', $formula, $match))
        {
            $expression = trim($match[1]);
            $mode = $match[2]; // s = sum
            $operator = $match[3];
            $target = intval($match[4]);

            return array(
                'type'           => 'dice_comparison',
                'dice_expr'      => $this->parseExpression($expression),
                'mode'           => $mode,
                'operator'       => $operator,
                'target'         => $target
            );
        }

        // Логические операторы (самый низкий приоритет)
        if (preg_match('/^(.+?[^cs](?:\s|\)))([><])\s*(.+)$/', $formula, $match))
        {
            $left = trim($match[1]);
            $operator = $match[2];
            $right = trim($match[3]);

            return array(
                'type'     => 'comparison',
                'left'     => $this->parseExpression($left),
                'operator' => $operator,
                'right'    => $this->parseExpression($right)
            );
        }

        // Сложение и вычитание (с правильным приоритетом - ищем справа налево)
        $pos = $this->findLastOperator($formula, array('+', '-'));
        if ($pos !== false)
        {
            $left = trim(substr($formula, 0, $pos));
            $operator = $formula[$pos];
            $right = trim(substr($formula, $pos + 1));

            return array(
                'type'     => 'binary_op',
                'left'     => $this->parseExpression($left),
                'operator' => $operator,
                'right'    => $this->parseExpression($right)
            );
        }

        // Умножение и деление (более высокий приоритет - ищем справа налево)
        $pos = $this->findLastOperator($formula, array('*', '/'));
        if ($pos !== false)
        {
            $left = trim(substr($formula, 0, $pos));
            $operator = $formula[$pos];
            $right = trim(substr($formula, $pos + 1));

            return array(
                'type'     => 'binary_op',
                'left'     => $this->parseExpression($left),
                'operator' => $operator,
                'right'    => $this->parseExpression($right)
            );
        }

        // Скобки
        if (preg_match('/^\((.+)\)$/', $formula, $match))
        {
            return $this->parseExpression($match[1]);
        }

        // Кубики с модификаторами и c>X
        if (preg_match('/^(\d*)d(\d+)(.*)$/', $formula, $match))
        {
            $count = empty($match[1]) ? 1 : intval($match[1]);
            $sides = intval($match[2]);
            $modifiersAndParams = $match[3];

            // Проверяем на c>X в конце (только для кубиков!)
            if (preg_match('/^(.*)c([><])(\d+)$/', $modifiersAndParams, $cMatch))
            {
                $remainingMods = $cMatch[1];
                $operator = $cMatch[2];
                $target = intval($cMatch[3]);

                // Создаем узел кубика без c>X
                $diceNode = $this->parseDiceNode($count, $sides, $remainingMods);

                // Оборачиваем в dice_count
                return array(
                    'type'           => 'dice_count',
                    'dice_expr'      => $diceNode,
                    'mode'           => 'c',
                    'operator'       => $operator,
                    'target'         => $target
                );
            }

            return $this->parseDiceNode($count, $sides, $modifiersAndParams);
        }

        // Число
        if (is_numeric($formula))
        {
            return array(
                'type'  => 'number',
                'value' => intval($formula)
            );
        }

        throw new Exception("Не удалось распарсить: ".$formula);
    }

    /**
     * Поиск последнего оператора с учетом скобок и приоритета
     */
    private function findLastOperator($formula, $operators)
    {
        $level = 0;
        $lastPos = false;

        for ($i = strlen($formula) - 1; $i >= 0; $i--)
        {
            $char = $formula[$i];

            if ($char === ')')
            {
                $level++;
            }
            elseif ($char === '(')
            {
                $level--;
            }
            elseif ($level === 0 && in_array($char, $operators))
            {
                $lastPos = $i;
            }
        }

        return $lastPos;
    }

    /**
     * Создание узла кубика
     */
    private function parseDiceNode($count, $sides, $modifiersAndParams)
    {
        // Разделяем модификаторы и параметры больше не нужно - параметры уже заменены
        $modifiers = trim($modifiersAndParams);

        return array(
            'type'       => 'dice',
            'count'      => $count,
            'sides'      => $sides,
            'modifiers'  => $this->parseModifiers($modifiers)
        );
    }

    /**
     * Парсинг модификаторов кубика
     */
    private function parseModifiers($modString)
    {
        $modifiers = array();
        $modString = trim($modString);

        // Парсим модификаторы по очереди
        while (!empty($modString))
        {
            $matched = false;

            // Keep highest/lowest
            if (preg_match('/^(k?[hl])(\d*)(.*)$/', $modString, $match))
            {
                $type = $match[1];
                $value = empty($match[2]) ? 1 : intval($match[2]);
                $modifiers[] = array('type' => $type, 'value' => $value);
                $modString = $match[3];
                $matched = true;
            }
            // Keep min/max
            elseif (preg_match('/^(km)(\d*)(.*)$/', $modString, $match))
            {
                $value = empty($match[2]) ? 1 : intval($match[2]);
                $modifiers[] = array('type' => 'km', 'value' => $value);
                $modString = $match[3];
                $matched = true;
            }
            // Drop highest/lowest
            elseif (preg_match('/^(d[hl])(\d*)(.*)$/', $modString, $match))
            {
                $type = $match[1];
                $value = empty($match[2]) ? 1 : intval($match[2]);
                $modifiers[] = array('type' => $type, 'value' => $value);
                $modString = $match[3];
                $matched = true;
            }
            // Drop min/max
            elseif (preg_match('/^(dm)(\d*)(.*)$/', $modString, $match))
            {
                $value = empty($match[2]) ? 1 : intval($match[2]);
                $modifiers[] = array('type' => 'dm', 'value' => $value);
                $modString = $match[3];
                $matched = true;
            }
            // Reroll
            elseif (preg_match('/^r(\d+)(.*)$/', $modString, $match))
            {
                $modifiers[] = array('type' => 'r', 'value' => intval($match[1]));
                $modString = $match[2];
                $matched = true;
            }
            // Reroll once
            elseif (preg_match('/^ro(\d+)(.*)$/', $modString, $match))
            {
                $modifiers[] = array('type' => 'ro', 'value' => intval($match[1]));
                $modString = $match[2];
                $matched = true;
            }
            // Exploding dice
            elseif (preg_match('/^([!x])(\d*)(.*)$/', $modString, $match))
            {
                $limit = empty($match[2]) ? 999 : intval($match[2]);
                $modifiers[] = array('type' => 'explode', 'limit' => $limit);
                $modString = $match[3];
                $matched = true;
            }

            if (!$matched)
            {
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
        switch ($ast['type'])
        {
            case 'number':
                return array(
                    'value'     => $ast['value'],
                    'detailed'  => strval($ast['value']),
                    'modifiers' => array()
                );

            case 'dice':
                return $this->rollDice($ast);

            case 'binary_op':
                $left = $this->evaluateAST($ast['left']);
                $right = $this->evaluateAST($ast['right']);

                $result = $this->applyBinaryOperator($left['value'], $ast['operator'], $right['value']);

                return array(
                    'value'     => $result,
                    'detailed'  => $left['detailed'].' '.$ast['operator'].' '.$right['detailed'].' = '.$result,
                    'modifiers' => array_merge($left['modifiers'], $right['modifiers'])
                );

            case 'comparison':
                $left = $this->evaluateAST($ast['left']);
                $right = $this->evaluateAST($ast['right']);

                $success = $this->applyComparison($left['value'], $ast['operator'], $right['value']);

                return array(
                    'value'     => $success ? 'Success' : 'Fail',
                    'detailed'  => $left['detailed'].' '.$ast['operator'].' '.$right['detailed'].' = '.($success ? 'Success' : 'Fail'),
                    'modifiers' => array_merge($left['modifiers'], $right['modifiers'])
                );

            case 'dice_comparison':
                // Обрабатываем сравнение результата (s>X)
                $exprResult = $this->evaluateAST($ast['dice_expr']);
                $success = $this->applyComparison($exprResult['value'], $ast['operator'], $ast['target']);
                $comparisonStr = 's' . $ast['operator'] . $ast['target'];

                return array(
                    'value'     => $success ? 'Success' : 'Fail',
                    'detailed'  => $exprResult['detailed'] . ' ' . $comparisonStr . ' = ' . ($success ? 'Success' : 'Fail'),
                    'modifiers' => $exprResult['modifiers']
                );

            case 'dice_count':
                // Обрабатываем подсчет кубиков (c>X) - только для кубиков!
                $diceResult = $this->evaluateAST($ast['dice_expr']);

                // Получаем исходные броски из результата кубика
                $rolls = $diceResult['rolls'];
                $count = 0;

                foreach ($rolls as $roll)
                {
                    if ($this->applyComparison($roll, $ast['operator'], $ast['target']))
                    {
                        $count++;
                    }
                }

                $comparisonStr = 'count dice ' . $ast['operator'] .' '. $ast['target'];
                preg_match('/^(.+)\s=\s\d+\s*$/',$diceResult['detailed'],$detailMatches);
                return array(
                    'value'     => $count,
                    'detailed'  => $detailMatches[1] . ' ' . $comparisonStr . ' = ' . $count,
                    'modifiers' => $diceResult['modifiers']
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
        for ($i = 0; $i < $count; $i++)
        {
            $rolls[] = mt_rand(1, $sides);
        }

        $originalRolls = $rolls;
        $detailed = $count.'d'.$sides.' ['.implode(', ', $rolls).']';

        // Применяем модификаторы к броскам
        foreach ($modifiers as $modifier)
        {
            $rolls = $this->applyModifier($rolls, $modifier, $sides);
        }

        $sum = array_sum($rolls);

        // Формируем детальное описание
        if ($rolls !== $originalRolls)
        {
            $detailed .= ' -> ['.implode(', ', $rolls).']';
        }

        $detailed .= ' = '.$sum;

        return array(
            'value'          => $sum,
            'detailed'       => $detailed,
            'rolls'          => $rolls,
            'modifiers'      => $modifiers,
            'original_rolls' => $originalRolls // Сохраняем для c>X
        );
    }

    /**
     * Применение модификатора к броскам
     */
    private function applyModifier($rolls, $modifier, $sides)
    {
        switch ($modifier['type'])
        {
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
                $result = array();
                for ($i = 0; $i < $modifier['value']; $i++)
                {
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
                for ($i = 0; $i < count($rolls); $i++)
                {
                    while ($rolls[$i] < $modifier['value'])
                    {
                        $rolls[$i] = mt_rand(1, $sides);
                    }
                }
                return $rolls;

            case 'ro':
                for ($i = 0; $i < count($rolls); $i++)
                {
                    if ($rolls[$i] < $modifier['value'])
                    {
                        $newRoll = mt_rand(1, $sides);
                        $rolls[$i] = max($rolls[$i], $newRoll);
                    }
                }
                return $rolls;

            case 'explode':
                $newRolls = array();
                foreach ($rolls as $roll)
                {
                    $newRolls[] = $roll;
                    $explosions = 0;
                    $currentRoll = $roll;

                    while ($currentRoll == $sides && $explosions < $modifier['limit'])
                    {
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
        switch ($operator)
        {
            case '+':
                return $left + $right;
            case '-':
                return $left - $right;
            case '*':
                return $left * $right;
            case '/':
                return $right != 0 ? intval($left / $right) : 0;
        }

        return 0;
    }

    /**
     * Применение оператора сравнения
     */
    private function applyComparison($left, $operator, $right)
    {
        switch ($operator)
        {
            case '>':
                return $left > $right;
            case '<':
                return $left < $right;
        }

        return false;
    }
}

// Интерфейс для хранилища параметров
interface ParamStoreInterface
{
    public function get($param);
}

// Простое хранилище параметров
class SimpleParamStore implements ParamStoreInterface
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
// Пример использования с отладочной информацией
$store = new SimpleParamStore(array(
    'weapon' => '1d8+3',
    'strength' => '4',
    'attack' => '1d20+&strength'
));

$roller = new DnDice($store);

// Тестируем математику с числами
$text1 = "Атака: d20+5";
echo "Тест 1: " . $text1 . "\n";
$results1 = $roller->processText($text1);
foreach ($results1 as $result) {
    echo "Оригинал: " . $result['original'] . "\n";
    echo "Формула: " . $result['formula'] . "\n";
    echo "Результат: " . $result['expanded'] . "\n\n";
}

// Тестируем c>X только для кубиков
$text2 = "Урон: 6d20x4c>15";
echo "Тест 2: " . $text2 . "\n";
$results2 = $roller->processText($text2);
foreach ($results2 as $result) {
    echo "Результат: " . $result['expanded'] . "\n";
}

// Тестируем s>X для любых выражений
$text3 = "Проверка: (d20+5)s>15";
echo "\nТест 3: " . $text3 . "\n";
$results3 = $roller->processText($text3);
foreach ($results3 as $result) {
    echo "Результат: " . $result['expanded'] . "\n";
}

// Тестируем параметры
$text4 = "Атака с параметром: &attack";
echo "\nТест 4: " . $text4 . "\n";
$results4 = $roller->processText($text4);
foreach ($results4 as $result) {
    echo "Оригинал: " . $result['original'] . "\n";
    echo "Формула: " . $result['formula'] . "\n";
    echo "Результат: " . $result['expanded'] . "\n";
}

// Дополнительный тест - простая математика
$text5 = "Просто: 2+3";
echo "\nТест 5: " . $text5 . "\n";
$results5 = $roller->processText($text5);
foreach ($results5 as $result) {
    echo "Результат: " . $result['expanded'] . "\n";
}
/**/

?>