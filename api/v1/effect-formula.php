<?php
declare(strict_types=1);

function normalizeEffectFormula(mixed $value): string {
    return is_string($value) ? str_replace(['x','×'], '*', strtolower(preg_replace('/\s+/u', '', $value) ?? '')) : '';
}
function parseEffectFormula(mixed $value): array {
    $formula=normalizeEffectFormula($value);$cursor=0;$count=0;
    $invalid=static function():never{throw new InvalidArgumentException('Formule invalide : dés, +, − et × sont calculés de gauche à droite.');};
    if($formula===''||strlen($formula)>100)$invalid();
    $expression=function(int $depth=0) use (&$expression,&$cursor,&$count,$formula,$invalid):array {
        if($depth>6)$invalid();$terms=[];$operator='+';
        if(in_array($formula[$cursor]??'',['+','-'],true))$operator=$formula[$cursor++];
        while($cursor<strlen($formula)){
            if($formula[$cursor]==='('){$cursor++;$term=$expression($depth+1);if(($formula[$cursor++]??'')!==')')$invalid();}
            else{
                if(preg_match('/^(?:(\d*)d(\d+)|(\d+))/',substr($formula,$cursor),$match)!==1||++$count>30)$invalid();$cursor+=strlen($match[0]);
                if(isset($match[2])&&$match[2]!==''){$dice=(int)($match[1]!==''?$match[1]:1);$sides=(int)$match[2];if($dice<1||$dice>100||$sides<2||$sides>1000)$invalid();$term=['dice'=>$dice,'sides'=>$sides,'min'=>$dice,'max'=>$dice*$sides];}
                else{$number=(int)$match[3];if(strlen($match[3])>10||$number>1000000000)$invalid();$term=['number'=>$number,'min'=>$number,'max'=>$number];}
            }
            $terms[]=['operator'=>$operator,'term'=>$term];
            if($cursor===strlen($formula)||$formula[$cursor]===')')break;
            $operator=$formula[$cursor++];
            if(!in_array($operator,['+','-','*'],true)||$cursor>=strlen($formula)||$formula[$cursor]===')')$invalid();
        }
        if(!$terms)$invalid();$min=0;$max=0;
        foreach($terms as $entry){$t=$entry['term'];$bounds=match($entry['operator']){
            '*'=>[$min*$t['min'],$min*$t['max'],$max*$t['min'],$max*$t['max']],
            '-'=>[$min-$t['max'],$max-$t['min']],default=>[$min+$t['min'],$max+$t['max']]};
            foreach($bounds as $n)if(!is_int($n)||abs($n)>9007199254740991)$invalid();
            $min=min($bounds);$max=max($bounds);
        }return ['terms'=>$terms,'min'=>$min,'max'=>$max];
    };
    $tree=$expression();if($cursor!==strlen($formula))$invalid();return ['formula'=>$formula,...$tree];
}
function validEffectFormula(mixed $value):bool {try{parseEffectFormula($value);return true;}catch(InvalidArgumentException){return false;}}
function evaluateEffectFormula(string $value,?callable $rollDie=null):array {
    $tree=parseEffectFormula($value);$dice=[];$rollDie??=static fn(int $sides):int=>random_int(1,$sides);
    $evaluate=function(array $tree,int $sign=1) use (&$evaluate,&$dice,$rollDie):array {
        $total=0;$breakdown='';
        foreach($tree['terms'] as $entry){$operator=$entry['operator'];$term=$entry['term'];
            if(isset($term['terms'])){$result=$evaluate($term,$sign*($operator==='-'?-1:1));$number=$result['total'];$display='('.$result['breakdown'].')';}
            elseif(isset($term['dice'])){$results=[];for($i=0;$i<$term['dice'];$i++){$n=$rollDie($term['sides']);if(!is_int($n)||$n<1||$n>$term['sides'])throw new InvalidArgumentException('Résultat de dé invalide.');$results[]=$n;}
                $number=array_sum($results);$display='['.implode(', ',$results).']';$dice[]=['count'=>$term['dice'],'sides'=>$term['sides'],'sign'=>$sign*($operator==='-'?-1:1),'results'=>$results];}
            else{$number=$term['number'];$display=(string)$number;}
            if($operator==='*'){$total*=$number;$breakdown='('.trim($breakdown).') × '.$display;}
            else{$total+=$operator==='-'?-$number:$number;$breakdown.=($operator==='-'?'−':($breakdown!==''?'+ ':'' )).$display;}
            $breakdown.=' ';
        }return ['total'=>$total,'breakdown'=>trim($breakdown)];
    };
    return [...$evaluate($tree),'formula'=>$tree['formula'],'dice'=>$dice,'rawD100'=>count($dice)===1&&$dice[0]['count']===1&&$dice[0]['sides']===100&&$dice[0]['sign']===1?$dice[0]['results'][0]:null];
}

function sumEffectFormulas(array $values):string {$formulas=array_map('normalizeEffectFormula',$values);return str_replace(['+-','++'],['-','+'],implode('+',array_map(static fn($v)=>count($formulas)>1&&preg_match('/[()*]/',$v)?'('.$v.')':$v,$formulas)))?:'0';}
