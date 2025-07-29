<?php

include_once '../ParamStoreInterface.php';
include_once '../DnDice.php';

use PHPUnit\Framework\TestCase;

class DnDiceTest extends TestCase
{
    private $store;
    private $DnDice;

    protected function setUp(): void {
        $this->store = new SimpleParamStore(['str'=>'+3','dex'=>'-1', 'attack'=>'f2d20h&str']);
        $this->nottestProcessText();
    }

    public function test__construct()
    {
        echo "created";
        $this->DnDice = new DnDice($this->store);
        $this->assertInstanceOf(DnDice::class, $this->DnDice);

    }

    public function validDataProvider()
    {
        return array(
            array(
                'text'=>'Обычная формула с спойлером s6d20kh4&str тут всякое бывает'
            ),
            array(
                'text'=>'s6d20kh4'
            ),
            array(
                'text'=>'6d20kh4&str > 35'
            ),
            array(
                'text'=>'s(6d20x4&str)s>35'
            ),
            array(
                'text'=>'s(6d20x4&str)c>10'
            ),
            array(
                'text'=>'Обычная формула с спойлером s6d20kh4&str тут всякое бывает'
            ),
            array(
                'text'=>'Обычная формула с спойлером s6d20kh4&str тут всякое бывает'
            ),
        );
    }
    public function nottestProcessText()
    {
        $this->DnDice = new DnDice($this->store);
        $data = array(
            array(
                'text'=>'Обычная формула с спойлером s6d20kh4&str тут всякое бывает'
            ),
            //array(
            //    'text'=>'s6d20kh4'
            //),
            //array(
            //    'text'=>'6d20kh4&str > 35'
            //),
            //array(
            //    'text'=>'s(6d20x4&str)s>35'
            //),
            array(
                'text'=>'s(6d20x4&str)c>10'
            ),
            array(
                'text'=>'Атака: &attack'
            ),
        );
        foreach ($data as $d) {
            print_r(array($d['text'],$this->DnDice->processText($d['text'])));
        }
    }
}
