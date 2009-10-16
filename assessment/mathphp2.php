<?php

//IMathAS:  ASCIIMath to PHP conversion
//(c) 2009 David Lippman

//This is a rewrite of mathphp using a tokenizing approach

//Based on concepts from mathjs from Peter Jipsen's ASCIIsvg.js
//script, (c) Peter Jipsen.  See /javascript/ASCIIsvg.js


function mathphppre($st) {
  if (strpos($st,"^-1") || strpos($st,"^(-1)")) {
		$st = str_replace(array("sin^-1","cos^-1","tan^-1","sin^(-1)","cos^(-1)","tan^(-1)"),array("asin","acos","atan","asin","acos","atan"),$st);
		$st = str_replace(array("sinh^-1","cosh^-1","tanh^-1","sinh^(-1)","cosh^(-1)","tanh^(-1)"),array("asinh","acosh","atanh","asinh","acosh","atanh"),$st);
  }
  return $st;
}
function mathphp($st,$varlist,$skipfactorial=false,$ignorestrings=true) {
	//translate a math formula to php function notation
	// a^b --> pow(a,b)
	// na --> n*a
	// (...)d --> (...)*d
	// n! --> factorial(n)
	// sin^-1 --> asin etc.
	
	//parenthesize variables with number endings, ie $c2^3 => ($c2)^3
	//not needed since mathphp no longer used on php vars
	
	//skipfactorial:  legacy: not really used anymore.  Originally intended
	//to handle !something type ifcond.  Might need to reexplore
	
	$vars = explode('|',$varlist);
	$st = makepretty($st);
	return mathphpinterpretline($st.' ',$vars,$ignorestrings);
  
}
//interpreter some code text.  Returns a PHP code string.
function mathphpinterpretline($str,$vars,$ignorestrings) {
	$str .= ';';
	$bits = array();
	$lines = array();
	$len = strlen($str);
	$cnt = 0;
	$lastsym = '';
	$lasttype = -1;
	$closeparens = 0;
	$symcnt = 0;
	//get tokens from tokenizer
	$syms = mathphptokenize($str,$vars,$ignorestrings);
	$k = 0;
	$symlen = count($syms);
	//$lines holds lines of code; $bits holds symbols for the current line. 
	while ($k<$symlen) {
		list($sym,$type) = $syms[$k];
		//first handle stuff that would use last symbol; add it if not needed
		if ($sym=='^' && $lastsym!='') { //found a ^: convert a^b to safepow(a,b)
			$bits[] = 'safepow(';
			$bits[] = $lastsym;
			$bits[] = ',';
			$k++;
			list($sym,$type) = $syms[$k];
			$closeparens++;  //triggers to close safepow after next token
			$lastsym='^';
			$lasttype = 0;
		} else if ($sym=='!' && $lasttype!=0 && $lastsym!='' && $syms[$k+1]{0}!='=') { 
			//convert a! to factorial(a), avoiding if(!a) and a!=b
			$bits[] = 'factorial(';
			$bits[] = $lastsym;
			$bits[] = ')';
			$sym = '';
		}  else {
			//add last symbol to stack
			if ($lasttype!=7 && $lasttype!=-1) {
				$bits[] = $lastsym;
			}
		}
		if ($closeparens>0 && $lastsym!='^' && $lasttype!=0) {
			//close safepow.  lasttype!=0 to get a^-2 to include -
			while ($closeparens>0) {
				$bits[] = ')';
				$closeparens--;
			}
			//$closeparens = false;
		}
		
		
		if ($type==7) {//end of line
			if ($lasttype=='7') {
				//nothing exciting, so just continue
				$k++;
				continue;
			}
			//check for for, if, where and rearrange bits if needed
			$forloc = -1;
			$ifloc = -1;
			$whereloc = -1;
			//collapse bits to a line, add to lines array
			$lines[] = implode('',$bits);
			$bits = array();
		} else if ($type==1) { //is var
			//implict 3$a and $a $b and (3-4)$a
			if ($lasttype==3 || $lasttype==1 || $lasttype==4) {
				$bits[] = '*';
			}
		} else if ($type==2) { //is func
			//implicit $v sqrt(2) and 3 sqrt(3) and (2-3)sqrt(4) and sqrt(2)sqrt(3)
			if ($lasttype==3 || $lasttype==1 || $lasttype==4 || $lasttype==2 ) {
				$bits[] = '*';
			}
		} else if ($type==3) { //is num
			//implicit 2 pi and $var pi
			if ($lasttype==3 || $lasttype == 1 || $lasttype==4) {
				$bits[] = '*';
			}
			
		} else if ($type==4) { //is parens
			//implicit 3(4) (5)(3)  $v(2)
			if ($lasttype==3 || $lasttype==4 || $lasttype==1) {
				$bits[] = '*';
			}
		} else if ($type==9) {//is error
			//tokenizer returned an error token - exit current loop with error
			return 'error';
		} else if ($sym=='-' && $lastsym=='/') {
			//paren 1/-2 to 1/(-2)
			//avoid bug in PHP 4 where 1/-2*5 = -0.1 but 1/(-2)*5 = -2.5
			$bits[] = '(';
			$closeparens++;
		}
			
		
		$lastsym = $sym;
		$lasttype = $type;
		$cnt++;
		$k++;
	}
	//if no explicit end-of-line at end of bits
	if (count($bits)>0) {
		$lines[] = implode('',$bits);
	}
	//collapse to string
	return implode(';',$lines);
}



function mathphptokenize($str,$vars,$ignorestrings) {
	global $allowedmacros;
	global $mathfuncs;
	global $disallowedwords,$disallowedvar;
	$lookfor = array_merge($vars, array("e","pi"));
	$maxvarlen = 0;
	foreach ($lookfor as $v) {
		$l = strlen($v);
		if ($l>$maxvarlen) {
			$maxvarlen = $l;
		}
	}
	$i=0;
	$cnt = 0;
	$len = strlen($str);
	$syms = array();
	while ($i<$len) {
		$cnt++;
		if ($cnt>100) {
			exit;
		}
		$intype = 0;
		$out = '';
		$c = $str{$i};
		$eatenwhite = 0;
		if ($c>="a" && $c<="z" || $c>="A" && $c<="Z") {
			//is a string or function name
			//need to handle things like:
			//function3(whee)
			//func_name(blah)
			// xy
			// ssin(s)
			// snsin(x)
			// nln(n)
			// ppi   and pip
			// pi
			
			$intype = 2; //string like function name
			do {
				$out .= $c;
				$i++;
				if ($i==$len) {break;}
				$c = $str{$i};
			} while ($c>="a" && $c<="z" || $c>="A" && $c<="Z" || $c>='0' && $c<='9' || $c=='_');
			//check if it's a special word
			if ($out=='e') {
				$out = "exp(1)";
				$intype = 3;
			} else if ($out=='pi') {
				$out = "(M_PI)";
				$intype = 3;
			} else {
				//eat whitespace
				while ($c==' ') {
					$i++;
					$c = $str{$i};
					$eatenwhite++;
				}    
				//if function at end, strip off function
				if ($c=='(' || ($c=='^' && (substr($str,$i+1,2)=='-1' || substr($str,$i+1,4)=='(-1)'))) {
					$outlen = strlen($out);
					$outend = '';
					for ($j=$outlen-1; $j>0; $j--) {
						$outend = $out{$j}.$outend;
						if (in_array($outend,$mathfuncs)) {
							$i = $i-$outlen+$j;
							$c = $str{$i};
							$out = substr($out,0,$j);
							break;
						}
					}
				}
				//could be sin^-1 or sin^(-1) - check for them and rewrite if needed
				if ($c=='^' && substr($str,$i+1,2)=='-1') {
					$i += 3;
					$out = 'arc'.$out;
					$c = $str{$i};
					while ($c==' ') {
						$i++;
						$c = $str{$i};
					}
				} else if ($c=='^' && substr($str,$i+1,4)=='(-1)') {
					$i += 3;
					$out = 'arc'.$out;
					$c = $str{$i};
					while ($c==' ') {
						$i++;
						$c = $str{$i};
					}
				}
				
				
				//if there's a ( then it's a function
				if ($c=='(' && $out!='e' && $out!='pi') {
					//look for xsin(  or nsin(  or  nxsin(
					
					//rewrite logs
					if ($out=='log') {
						$out = 'log10';
					} else if ($out=='ln') {
						$out = 'log';
					} else {
						//check it's an OK function
						if (!in_array($out,$allowedmacros)) {
							echo "Eeek.. unallowed macro {$out}";
							return array(array('',9));
						}
					}
					//rewrite arctrig into atrig for PHP
					$out = str_replace(array("arcsin","arccos","arctan","arcsinh","arccosh","arctanh"),array("asin","acos","atan","asinh","acosh","atanh"),$out);
	  
					//connect upcoming parens to function
					$connecttolast = 2;
				} else {
					//look for xpi,  pix,  ppi,  xe
					//not a function, so what is it?
					if (in_array($out,$vars)) {
						$intype = 4;
						$out = '('.$out.')';
					} else if ($out=='true' || $out=='false' || $out=='null') {
						//we like this - it's an acceptable unquoted string
					} else {
						$intype = 6;
						//look for varvar
						$outlen = strlen($out);
						$outst = '';
						for ($j=min($maxvarlen,$outlen-1); $j>0; $j--) {
							$outst = substr($out,0,$j);			
							if (in_array($outst,$lookfor)) {
								$i = $i - $outlen + $j - $eatenwhite;
								$c = $str{$i};
								$out = $outst;
								if ($out=='e') {
									$out = "exp(1)";
									$intype = 3;
								} else if ($out=='pi') {
									$out = "(M_PI)";
									$intype = 3;
								} else {
									if (in_array($out,$vars)) {
										$out = '('.$out.')';
										$intype = 4;
									}
								}
								break;
								
							}
							
						}
						//quote it if not a variable
						if ($intype == 6 && $ignorestrings) {
							$out = "'$out'";
						}
							
					}
					
					/*if (isset($GLOBALS['teacherid'])) {
						//an unquoted string!  give a warning to instructor, 
						//but treat as a quoted string.
						echo "Warning... unquoted string $out.. treating as string";
						$out = "'$out'";
						$intype = 6;
					}
					*/
					
				}
			}
		} else if (($c>='0' && $c<='9') || ($c=='.'  && ($str{$i+1}>='0' && $str{$i+1}<='9')) ) { //is num
			$intype = 3; //number
			$cont = true;
			//handle . 3 which needs to act as concat
			if ($lastsym[0]=='.') {
				$syms[count($syms)-1][0] .= ' ';
			}
			do {
				$out .= $c;
				$lastc = $c;
				$i++;
				if ($i==$len) {break;}
				$c= $str{$i};
				if (($c>='0' && $c<='9') || ($c=='.' && $str{$i+1}!='.' && $lastc!='.')) {
					//is still num
				} else if ($c=='e' || $c=='E') {
					//might be scientific notation:  5e6 or 3e-6 
					$d = $str{$i+1};
					if ($d>='0' && $d<='9') {
						$out .= $c;
						$i++;
						if ($i==$len) {break;}
						$c= $str{$i};
					} else if ($d=='-' && ($str{$i+2}>='0' && $str{$i+2}<='9')) {
						$out .= $c.$d;
						$i+= 2;
						if ($i>=$len) {break;}
						$c= $str{$i};
					} else {
						$cont = false;
					}	
				} else {
					$cont = false;
				}	
			} while ($cont);
		} else if ($c=='(' || $c=='{' || $c=='[') { //parens or curlys
			if ($c=='(') {
				$intype = 4; //parens
				$leftb = '(';
				$rightb = ')';
			} else if ($c=='{') {
				$intype = 5; //curlys
				$leftb = '{';
				$rightb = '}';
			} else if ($c=='[') {
				$intype = 11; //array index brackets
				$leftb = '[';
				$rightb = ']';
			}
			$thisn = 1;
			$inq = false;
			$j = $i+1;
			$len = strlen($str);
			while ($j<$len) {
				//read terms until we get to right bracket at same nesting level
				//we have to avoid strings, as they might contain unmatched brackets
				$d = $str{$j};
				if ($inq) {  //if inquote, leave if same marker (not escaped)
					if ($d==$qtype && $str{$j-1}!='\\') {
						$inq = false;
					}
				} else {
					if ($d=='"' || $d=="'") {
						$inq = true; //entering quotes
						$qtype = $d;
					} else if ($d==$leftb) {
						$thisn++;  //increase nesting depth
					} else if ($d==$rightb) {
						$thisn--; //decrease nesting depth
						if ($thisn==0) {
							//read inside of brackets, send recursively to interpreter
							$inside = mathphpinterpretline(substr($str,$i+1,$j-$i-1),$vars,$ignorestrings);
							if ($inside=='error') {
								//was an error, return error token
								return array(array('',9));
							}
							//if curly, make sure we have a ;, unless preceeded by a $ which
							//would be a variable variable
							if ($rightb=='}' && $lastsym[0]!='$') {
								$out .= $leftb.$inside.';'.$rightb;
							} else {
								$out .= $leftb.$inside.$rightb;
							}
							$i= $j+1;
							break;
						}
					} else if ($d=="\n") {
						//echo "unmatched parens/brackets - likely will cause an error";
					}
				}
				$j++;
			}
			if ($j==$len) {
				$i = $j;
				echo "unmatched parens/brackets - likely will cause an error";
			} else {
				$c = $str{$i};
			}
		} else if ($c=='"' || $c=="'") { //string
			$intype = 6;
			$qtype = $c;
			do {
				$out .= $c;
				$i++;
				if ($i==$len) {break;}
				$lastc = $c;
				$c = $str{$i};
			} while (!($c==$qtype && $lastc!='\\'));	
			$out .= $c;
			if (!$ignorestrings) {
				$inside = mathphpinterpretline(substr($out,1,strlen($out)-2),$vars,$ignorestrings);
				if ($inside{0}=='\'' && $inside{strlen($inside)-1}=='\'') {
					$inside = substr($inside,1,strlen($inside)-2);
				} 
				$out= $qtype . $inside . $qtype;
				
			}
							
			$i++;
			$c = $str{$i};
		} else if ($c=="\n") {
			//end of line
			$intype = 7;
			$i++;
			$c = $str{$i};
		} else if ($c==';') {
			//end of line
			$intype = 7;
			$i++;
			$c = $str{$i};
		} else {
			//no type - just append string.  Could be operators
			$out .= $c;
			$i++;
			$c = $str{$i};
		}
		while ($c==' ') { //eat up extra whitespace
			$i++;
			if ($i==$len) {break;}
			$c = $str{$i};
			if ($c=='.' && $intype==3) {//if 3 . needs space to act like concat
				$out .= ' ';
			}
		}
		//if parens or array index needs to be connected to func/var, do it
		if ($connecttolast>0 && $intype!=$connecttolast) {
			
			$syms[count($syms)-1][0] .= $out;
			$connecttolast = 0;
			if ($c=='[') {// multidim array ref?
				$connecttolast = 1;
			}
			
		} else {
			//add to symbol list, avoid repeat end-of-lines.
			if ($intype!=7 || $lastsym[1]!=7) {
				$lastsym = array($out,$intype);
				$syms[] =  array($out,$intype);
			}
		}
		
	}
	return $syms;
}

function safepow($base,$power) {
	if ($base==0) {if($power==0) {return sqrt(-1);} else {return 0;}}
	if ($base<0 && floor($power)!=$power) {
		for ($j=3; $j<50; $j+=2) {
			if (abs(round($j*$power)-($j*$power))<.000001) {
				if (round($j*$power)%2==0) {
					return exp($power*log(abs($base)));
				} else {
					return -1*exp($power*log(abs($base)));
				}
			}
		}
		return sqrt(-1);
	}
	if (floor($base)==$base && floor($power)==$power && $power>0) { //whole # exponents
		$result = pow(abs($base),$power);
	} else { //fractional & negative exponents (pow can't handle?)
		$result = exp($power*log(abs($base)));
	}
	if (($base < 0) && ($power % 2 != 0)) {
		$result = -($result);
	}
	return $result;
}

function factorial($x) {
	for ($i=$x-1;$i>0;$i--) {
		$x *= $i;	
	}
	return ($x<0?false:($x==0?1:$x));
}
//basic trig cofunctions
function sec($x) {
	return (1/cos($x));
}
function csc($x) {
	return (1/sin($x));
}
function cot($x) {
	return (1/tan($x));
}
function sech($x) {
	return (1/cosh($x));
}
function csch($x) {
	return (1/sinh($x));
}
function coth($x) {
	return (1/tanh($x));
}
			
?>
