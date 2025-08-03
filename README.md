# DnDice
A library for parsing dice roll formulas.

## List of Supported Modifiers

### Suffixes (placed after dice MdG, where M = number of dice, G = number of sides)

`h`, `kh`, `khX` → keep the highest 1 or X dice
`l`, `kl`, `klX` → keep the lowest 1 or X dice
`km`, `kmX` → keep minimum and maximum 1 or X dice where X < (M (number of dice) / 2)

`dh`, `dhX` → drop the highest 1 or X dice
`dl`, `dlX` → drop the lowest 1 or X dice
`dm`, `dmX` → drop minimum and maximum 1 or X dice where X < (M (number of dice) / 2)

`rX` → reroll values less than X (X ≤ G (number of sides))
`roX` → reroll values less than X once, take the maximum (X ≤ G (number of sides))
`!`, `x`, `xN` → exploding dice (if maximum is rolled, roll another die of the same type) (if N is specified, no more than N times)

`+`, `-`, `*`, `/` → standard mathematical operations
`(`, `)` → grouping functions

` > `, ` < ` → logical functions, both sides can be formulas or numbers, must be separated by spaces. Result: Success/Fail

`c>X`, `c<X` → count of dice that rolled higher or lower than X (where X ≤ number of sides)

### Postfixes (placed after the formula)

`s>X`, `s<X` → sum greater than, less than X - Result: Success/Fail (analogous to logical functions with number on the right)

`&paramName` → add a piece of formula from `paramName` to the formula (requires external implementation)

### Prefixes

`s` → hide under spoiler
`f` → show rolled dice and calculations


# DnDice Ru
Библиотека для парсинга формул бросков кубов.

## Список поддерживаемых модификаторов
### Суфиксы (располагаются после дайсов MdG. M - количество кубов. G - количество граней)
`h`, `kh`, `khX` -> оставить лучший 1 или X костей
`l`, `kl`, `klX` -> оставить худший 1 или X костей
`km`, `kmX` -> оставить минимальные и максимальные 1 или X где X &lt (M (количество костей) / 2)

`dh`, `dhX` -> убрать лучший 1 или X костей
`dl`, `dlX` -> убрать худший 1 или X костей
`dm`, `dmX` -> убрать минимальную и максимальную 1 или X костей где X &lt (M (количество костей) / 2)

`rX` - Перебросить значения меньше X (X <= G (количеству граней))
`roX` - перебросить значение меньше X один раз, взять максимальное. (X <= G (количеству граней))
`!`, `x`, `xN` - Взрывные Кубы (Если выпал максимум, бросить еще такой же куб) (Если указан N не более N раз)

`+`, `-`, `*`, `/` - обычные математические функции.
`(`,`)` - группировочные функции

` > `, ` < ` - логические функции, по обе части могут быть как формулы так и числа, должны быть отделены пробелами Ответ - Success/Fail

`c>X`, `c<X` - Количество Кубов на которых выпало больше или меньше X (где X <= Количеству Граней)

### Постфиксы (Располагаются после формулы)
`s>X`, `s<X` - сумма больше, меньше X - Ответ - Success/Fail аналог логических функций с числом справа.


`&paramName` - добавить к формуле кусок формулы из `paramName` (требуется внешняя реализация)

### Префиксы

`s` - убрать под спойлер.
`f` - показать выпавшие дайсы и расчеты.


# Links / Ссылки

Используется в telegram боте: https://t.me/dice_n_dice_bot