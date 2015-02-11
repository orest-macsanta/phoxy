<?php

function NameParsedArray( $a, $b, $c )
{
  return array("dir" => $a, "class" => $b, "method" => $c);
}

function ParseLazy( $str )
{
  $res = explode("/", $str);
  if (end($res) == '')
    array_pop($res);
  if (count($res) <= 3)
  {
    if (count($res) == 1)
      return NameParsedArray("", $res[0], false);
    if (count($res) == 2)
      return NameParsedArray("", $res[0], $res[1]);
    return NameParsedArray($res[0], $res[1], $res[2]);
  }
  $func = array_pop($res);
  $class = array_pop($res);
  return NameParsedArray(implode("/", $res), $class, $func);
}

function ParseGreedy( $str )
{
  $res = ParseLazy($str);
  if (!$res['method'])
  {
    $res['method'] = 'Reserve';
    return $res;
  }
  return NameParsedArray(implode("/", array($res["dir"], $res["class"])), $res["method"], "Reserve");
}

function TryExtractParams( $str, $support_array = false)
{
  if (phoxy_conf()['debug'])
    var_dump("WE PARSING",  $str, $support_array);
  $length = strlen($str);
  $i = -1;

  while (++$i < $length)
  {
    if ($str[$i] == '(')
      break;
    if ($support_array && $str[$i] == '[')
      break;
  }
  if ($i >= $length)
    return null;
  $array_mode = (int)($str[$i] == '[');

  $ConstructParameter = function($str, $start, $end)
  {
    $param = stripslashes(substr($str, $start, $end));
    if (strlen($param) < 2)
      return $param;
    if ($param[0] != "\"" && $param[1] != "'")
      return $param;
    return substr($param, 1, strlen($param) - 2);
  };


  $began = $i + 1;

  $escape = 0;
  $nested = $array_mode;
  $mode = 0;
  $args = [];
  $argbegin = $began;

  $expect_join = false;
  while (++$i < $length)
  {
    $ch = $str[$i];
    if (phoxy_conf()['debug'])
    {
      echo "<br>$ch res: ".$ConstructParameter($str, $argbegin, $i - $argbegin + 1);
      echo "<br>";
      print_r($args);
    }
    if ($escape)
      $escape = 0;
    else if ($ch == "\"" || $ch == "'")
    {
      if ($mode == 1)
        $mode = 0;
      else
        $mode = 1;
    }
    else if (($ch == "\\" || $ch == "/") && $mode == 1)
      $escape = 1;
    else if ($ch == '[' && !$mode)
      $nested++;
    else if ($ch == ']' && !$mode)
    {
      $nested--;
      if ($nested < 0)
        break;

      if ($nested - $array_mode > 1)
        continue;

      echo "<hr>";
      $new = $ConstructParameter($str, $argbegin, $i - $argbegin);
      if ($nested - $array_mode == 0)
        $new = TryExtractParams($new.']', true);
      echo "<hr>";

      if (phoxy_conf()['debug'])
        var_dump($new);
      if (!$expect_join)
        $args[] = $new;
      else
      {
        $key = array_pop($args);
        $args[$key] = $new;
        if (phoxy_conf()['debug'])
        {
          echo "POP";
          var_dump($args);
        }
      }

      if ($array_mode)
        break;

      $argbegin = $i + 1;
      $expect_join = false;
      if (@$str[$argbegin] == ',') // or != ) and != ], maybe
      {
        $argbegin++;
        $i++;
      }

      continue;
    }
    else if ($ch == ')' && !$mode)
      break;
    else if ($ch == ',' && !$mode)
    {
      if (phoxy_conf()['debug'])
        var_dump($nested);
      if ($nested < 0)
        die("Deserealisation fail: Unexpected ']' found at $i");
      if ($nested > $array_mode)
        continue;
      $new = $ConstructParameter($str, $argbegin, $i - $argbegin);
      if (!$expect_join)
        $args[] = $new;
      else
      {
        if (phoxy_conf()['debug'])
          echo "POP";
        $key = array_pop($args);
        $args[$key] = $new;
      }

      $argbegin = $i + 1;
      $expect_join = false;
    }
    else if ($support_array && $ch == ':' && !$mode)
    {
      if (phoxy_conf()['debug'])
        echo "IM IN [".($i - $argbegin)."]:".substr($str, $argbegin);
      $args[] = $ConstructParameter($str, $argbegin, $i - $argbegin);
      $argbegin = $i + 1;
      $expect_join = $support_array;
    }
  }
  if ($nested)
    die("Deserealisation fail: Wrong nesting level $nested");

  if ($i >= $length)
    return null;
  if ($array_mode)
  {
    if ($str[$i] != ']')
      return null;
    return $args;
  }

  if ($str[$i] != ')')
    return null;

  if ($i != $argbegin)
    $args[] = $ConstructParameter($str, $argbegin, $i - $argbegin);
  $end = $i + 1;

  if (phoxy_conf()['debug'])
  var_dump($args);
  return
  [
    "module" => substr($str, 0, $began - 1),
    "arguments" => $args,
    "ending" => substr($str, $end),
  ];
}

function GetRpcObject( $str, $get )
{
  $args = TryExtractParams($str);
  if ($args != null)
  {
    $str = $args['module'];
    $get = $args['arguments'];
  }

  $greedy = ParseGreedy($str);
  $lazy = ParseLazy($str);
  
  if (!$lazy['method'])
    $lazy['method'] = 'Reserve';
  $try = array($greedy, $lazy);
    
  include_once('include.php');

  foreach ($try as $t)
  {
    if (!$t['class'] || !$t['method'])
      continue;

    if ($t['class'] == 'phoxy') // reserved module name
      $target_dir = realpath(dirname(__FILE__));
    else
      $target_dir = phoxy_conf()["api_dir"];
    
    $obj = IncludeModule($target_dir.'/'.$t["dir"], $t["class"]);
    if (!is_null($obj))
      return
      [
        "original_str" => $str,
        "obj" => $obj,
        "method" => $t["method"],
        "args" => $get,
      ];
  }
  exit(json_encode(["error" => 'Module not found']));
}
