#!/usr/bin/env php
<?php
require './vendor/autoload.php';
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

$parser        = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$traverser     = new NodeTraverser;
$prettyPrinter = new PrettyPrinter\Standard;

$log = fopen('php://stderr','a');
function pp($msg) {
    global $log;
    fwrite($log, $msg);
}
$code = file_get_contents("php://stdin");
// parse
$stmts = $parser->parse($code);

$tainted_vars = [];
$source_array = ['_GET'=>1, '_POST'=>1, '_COOKIE'=>1, '_ENV'=>1];
$source_func = ['file_get_contents'=>1, 'mysql_fetch_row'=>1];
$sql_sinks = ['pg_query'=>1];
$cmdl_sinks = ['system'=>1];
$user_funcs = []; // a map of user defined functions
/* construct funcs dict */
foreach($stmts as $stmt) {
    if ($stmt instanceof Node\Stmt\Function_) {
        $user_funcs[$stmt->name] = $stmt;
    }
}
//var_dump($user_funcs);
enter_call($stmts, $tainted_vars);
// var_dump($tainted_vars);
pp($code);

// function do_call($func_call, $caller_table) {
//     global $user_funcs;
//     $callee_table = gen_sym_table($func_call, $caller_table);
//     enter_call($user_funcs[$func_call->name], $callee_table);
// }
    
    
function enter_call($func_stmts, $sym_table) {   
    /* only consider assign for now */
    foreach ($func_stmts as $stmt) {
        echo "process one stmt...\n";
        if ($stmt instanceof Node\Expr\Assign) {
            echo "process assign...\n";
            $istainted = eval_expr($stmt->expr, $sym_table);
            if ($istainted) {
                echo "add {$stmt->var->name}\n";
                $sym_table[$stmt->var->name] = 1;
            }
        }
        else if ($stmt instanceof Node\Expr\FuncCall) {
            echo "call:", $stmt->name, "\n";
            eval_expr($stmt, $sym_table);
            //do_call($stmt, $sym_table);
            
        }
        else if ($stmt instanceof Node\Stmt\Function_) {
            /* skip declare statement */
            continue;
        }
        else if ($stmt instanceof PhpParser\Node\Stmt\Return_) {
            return eval_expr($stmt->expr, $sym_table);
        }
        else {
            echo "unsupported statement type ".get_class($stmt)."\n";
        }
        
        //$traverser->traverse(array($stmt));
    }
    return false;
}

function gen_sym_table($call_site, $func_proto, $caller_table) {
    $newtable = [];
    assert(count($func_proto->params) == count($call_site->args), "parameters and arguments should have same numbers");
    for ($i = 0; $i < count($func_proto->params); $i++) {
        $params = $func_proto->params;
        $param = $params[$i];
        $args = $call_site->args;
        $newtable[$param->name] = eval_expr($args[$i], $caller_table);
    }
    return $newtable;
}

function check_var($name, $sym_table) {
    echo "check var $name\n";
    if (array_key_exists($name, $sym_table)) {
        return true;
    }
    else {
        return false;
    }    
}

function is_sink($func_name) {
    global $sql_sinks, $cmdl_sinks;
    if (array_key_exists($func_name, $sql_sinks)) {
        return true;
    }
    return false;
}

function is_args_tainted($func, $sym_table) {
    foreach($func->args as $arg) {
        if (eval_expr($arg, $sym_table)) {        
            return true;
        }
    }
    return false;
}

function is_source_array($name) {
    global $source_array;
    if (array_key_exists($name, $source_array)) {
        return true;
    }
    else {
        return false;
    }
}

function is_source_func($name) {
    global $source_func;
    if (array_key_exists($name, $source_func)) {
        return true;
    }
    else {
        return false;
    }
}

function eval_expr($expr, $sym_table) {
    global $user_funcs;
    $expr_type = get_class($expr);
    if ($expr instanceof Node\Expr\Variable) {
        echo "evaluate var {$expr->name}...\n";
        if (check_var($expr->name, $sym_table)) {
            return true;
        }
        else {
            return false;
        }
    }
    else if ($expr instanceof Node\Scalar\LNumber) {

        echo "evaluate lnumber {$expr->value}...\n";
        return false;
    }
    else if ($expr instanceof Node\Scalar\String_) {
        echo "evaluate string {$expr->value}...\n";
        return false;
    }

    // else if ($expr instanceof Node\Expr\ArrayDimFetch) {
    //     echo "evaluate arraydimfetch...\n";
    //     if (is_source_array($expr->var->name)) {
    //         return true;
    //     }
    //     else {
    //         return false;
    //     }
    // }
    else if ($expr instanceof Node\Expr\BinaryOp) {
        echo "evaluate binaryOp $expr_type...\n";
        return eval_expr($expr->left, $sym_table) || eval_expr($expr->right, $sym_table);
    }
    else if ($expr instanceof Node\Scalar\Encapsed) {
        echo "evaluate binaryOp $expr_type...\n";
        foreach($expr->parts as $part) {
            if (eval_expr($part, $sym_table)) {
                return true;
            }
        }
        return false;
    }
    else if ($expr instanceof Node\Scalar\EncapsedStringPart) {
        echo "evaluate EncapsedStringPart $expr->value...\n";
        return false;
    }
    else if ($expr instanceof Node\Expr\FuncCall) {
        $func = $expr;
        $func_name = $func->name->parts[0];
        echo "evaluate funcCall $func_name...\n";
        if (is_sink($func_name)) {
            if (is_args_tainted($func, $sym_table))
            {
                echo "SQL injection vulnerability found in line {$func->getline()}\n";
               // throw new Exception("SQL injection vulnerability found in line {$expr->getline()}");
            }
            else {
                return false;
            }
            
        }
        else if (is_source_func($func_name)) {
            return true;
        }
        /* if user defined function */
        else if (array_key_exists($func_name, $user_funcs)) {
            $call_site = $func;
            $func_proto = $user_funcs[$func_name];
            $callee_table = gen_sym_table($call_site, $func_proto, $sym_table);
            return enter_call($func_proto->stmts, $callee_table);
        }
        /* if built-in function */
        else {            
            if (is_args_tainted($func, $sym_table)) {
                return true;
            }
            else {
                return false;
            }
        }
        return false;
    }
    else if ($expr instanceof Node\Arg) {
        return eval_expr($expr->value, $sym_table);
        
    }
            
    else {
        echo "unsupported expr type: $expr_type\n";
        var_dump($expr);
    }
}
?>