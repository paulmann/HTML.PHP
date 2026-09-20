<?php
/**
 * 02 - Variable substitution: %{NAME}, #{NAME}, %[NAME], @{NAME} and the
 *      numeric directives.
 */

declare(strict_types=1);

group('Variables: substitution forms');

test('%{NAME} is replaced by the resolved value', function (): void {
    $result = processHtml('Hello %{NAME}!', static fn(string $name): string => $name === 'NAME' ? 'World' : '');

    assertSame('Hello World!', $result);
});

test('#{NAME} is replaced by the resolved value', function (): void {
    $result = processHtml('Hello #{NAME}!', static fn(string $name): string => $name === 'NAME' ? 'World' : '');

    assertSame('Hello World!', $result);
});

test('The resolver receives the variable name unchanged', function (): void {
    $seen = [];
    $collect = static function (string $name) use (&$seen): string {
        $seen[] = $name;

        return '';
    };

    processHtml('%{Alpha}', $collect);
    processHtml('#{Beta}', $collect);

    assertSame(['Alpha', 'Beta'], $seen);
});

test('A translation key reaches the resolver with its prefix intact', function (): void {
    $seen = [];
    processHtml('@{Hello}', static function (string $name) use (&$seen): string {
        $seen[] = $name;

        return '';
    });

    assertSame(['Translate.Hello'], $seen);
});

test('A variable repeated in one template is resolved every time', function (): void {
    $calls = 0;
    $result = processHtml('%{N}%{N}', static function (string $name) use (&$calls): string {
        ++$calls;

        return 'x';
    });

    assertSame('xx', $result);
    assertSame(2, $calls);
});

test('An unresolved name becomes the empty string', function (): void {
    $result = processHtml('[%{MISSING}]', static fn(string $name): string => '');

    assertSame('[]', $result);
});

test('%{...} accepts a dot separated name such as a translation key', function (): void {
    $seen = [];
    processHtml('%{Translate.Hello}', static function (string $name) use (&$seen): string {
        $seen[] = $name;

        return '';
    });

    assertSame(['Translate.Hello'], $seen);
});

test('The bracketed form %[NAME] is unescaped to %{NAME}', function (): void {
    $result = processHtml('%[NAME]', static fn(string $name): string => 'x');

    assertSame('%{NAME}', $result);
});

test('A bracketed reference survives the second evaluation pass', function (): void {
    $GLOBALS['NAME'] = 'World';

    assertSame('Hi World!', HTML('Hi %[NAME]!'));
});

test('The bracketed form #[] is unescaped to #{NAME}', function (): void {
    // _htmlProcessVariables() rewrites the bracket form using the active symbol.
    assertSame('#{NAME}', _htmlProcessVariables('#[NAME]', static fn(string $name): string => 'x', '#'));
});

test('A custom exec symbol replaces the default one', function (): void {
    $result = _htmlProcessVariables('#{NAME}', static fn(string $name): string => 'x', '#');

    assertSame('x', $result);
});

test('The brace indirection %{%{NAME}} resolves the inner name first', function (): void {
    $result = processHtml('%{%{NAME}}', static function (string $name): string {
        return match ($name) {
            'NAME' => 'OTHER',
            'OTHER' => 'done',
            default => '',
        };
    });

    assertSame('done', $result);
});

test('@{NAME} is resolved through the Translate. key', function (): void {
    $result = processHtml('[@{Hello}]', static function (string $name): string {
        return $name === 'Translate.Hello' ? 'Privet' : '';
    });

    assertSame('[Privet]', $result);
});

test('@{NAME} falls back to the literal name when no translation exists', function (): void {
    $result = processHtml('[@{Hello}]', static fn(string $name): string => '');

    assertSame('[Hello]', $result);
});

test('A template without directives is returned unchanged', function (): void {
    $template = '<h1>Plain</h1><p>No variables here.</p>';

    assertSame($template, processHtml($template, static fn(string $name): string => 'x'));
});

group('Variables: numeric directives');

test('The Div directive divides the resolved value', function (): void {
    $GLOBALS['AMT'] = 10;

    assertSame('2.5', processHtml('%{AMT.Div 4}', 'update_template'));
    assertSame('0', processHtml('%{AMT.Div 0}', 'update_template'));
});

test('The Round directive rounds to the requested precision', function (): void {
    $GLOBALS['AMT'] = '10';
    $GLOBALS['HALF'] = 10.5;

    assertSame('10.00', processHtml('%{AMT.Round: 2}', 'update_template'));
    assertSame('10.5', processHtml('%{HALF.Round: 1}', 'update_template'));
});

test('The Clean Round directive drops a zero fraction', function (): void {
    $GLOBALS['AMT'] = 10;
    $GLOBALS['FRAC'] = 10.25;

    assertSame('10', processHtml('%{AMT.Clean Round: 2}', 'update_template'));
    assertSame('10.25', processHtml('%{FRAC.Clean Round: 2}', 'update_template'));
});

test('The Round Div and Clean Round Div directives combine both steps', function (): void {
    $GLOBALS['AMT'] = 10;

    assertSame('2.50', processHtml('%{AMT.Round Div 4: 2}', 'update_template'));
    assertSame('2.50', processHtml('%{AMT.Clean Round Div 4: 2}', 'update_template'));
    assertSame('0', processHtml('%{AMT.Clean Round Div 0: 2}', 'update_template'));
});

test('The numeric directives accept a comma decimal separator', function (): void {
    $GLOBALS['EURO'] = '1 234,56';

    assertSame('1234.56', processHtml('%{EURO.Clean Round: 2}', 'update_template'));
    // A bare Div keeps the full 14 digit form; Round Div trims it.
    assertSame('308.63999999999999', processHtml('%{EURO.Div 4}', 'update_template'));
    assertSame('308.64', processHtml('%{EURO.Round Div 4: 2}', 'update_template'));
});

test('The numeric directives are case insensitive', function (): void {
    $GLOBALS['AMT'] = 8;

    assertSame('4', processHtml('%{AMT.clean round div 2: 0}', 'update_template'));
});

group('Variables: legacy callback');

test('processHtml() accepts the legacy update_template callback', function (): void {
    $GLOBALS['NAME'] = 'legacy';

    assertSame('Hello legacy', processHtml('Hello %{NAME}', 'update_template'));
});

test('update_template() returns a string for a scalar global', function (): void {
    $GLOBALS['NUM'] = 7;
    $GLOBALS['RATIO'] = 3.5;
    $GLOBALS['FLAG'] = true;
    $GLOBALS['OFF'] = false;

    assertSame('7', update_template('NUM'));
    assertSame('3.5', update_template('RATIO'));
    assertSame('1', update_template('FLAG'));
    assertSame('', update_template('OFF'));
});

test('update_template() returns an empty string for a non scalar global', function (): void {
    $GLOBALS['LIST'] = [1, 2, 3];

    assertSame('', update_template('LIST'));
    assertSame('', update_template('NOT_DEFINED_ANYWHERE'));
});

test('update_template() falls back to the Translate. suffix', function (): void {
    assertSame('Checkout', update_template('Translate.Checkout'));
    assertSame('Checkout', processHtml('@{Checkout}', 'update_template'));
});

test('update_template() prefers an explicit translation global', function (): void {
    $GLOBALS['Translate.Checkout'] = 'Oformlenie';

    assertSame('Oformlenie', update_template('Translate.Checkout'));
});

test('A null global reads as an empty string', function (): void {
    $GLOBALS['NOTHING'] = null;

    assertSame('', update_template('NOTHING'));
    assertSame('[]', processHtml('[%{NOTHING}]', 'update_template'));
});

group('Variables: globals round trip');

test('HTML() renders a template from $GLOBALS and leaves no counter behind', function (): void {
    $GLOBALS['TITLE'] = 'Round trip';

    assertSame('<h1>Round trip</h1>', HTML('<h1>%{TITLE}</h1>'));
    assertFalse(array_key_exists('HTML_Prepare_Count', $GLOBALS), 'HTML() must not leak its recursion counter');
});

test('HTML() publishes values that survive into a second render', function (): void {
    $first = HTML('<!--#set var="CARRIED" value="set" -->%{CARRIED}');

    assertSame('set', $first);
    assertSame('set', HTML('%{CARRIED}'));
});

test('HTML() runs the SSI pass before the variable pass', function (): void {
    assertSame('42', HTML('<!--#set var="ANSWER" value="42" -->%{ANSWER}'));
});

test('HTML() resolves a variable published by an SSI directive in the same template', function (): void {
    $rendered = HTML('<!--#set var="GREETING" value="hello" --><p>%{GREETING}</p>');

    assertSame('<p>hello</p>', $rendered);
    assertSame('hello', $GLOBALS['GREETING']);
});
