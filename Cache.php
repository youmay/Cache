<?
/**
* PHP скрипт для полного сохранения веб-страницы с картинками, стилями, джаваскриптом, флэшем и т.д.
* 
* @version 1.0
* @author Kirill Shaparov <kirill@shaparov.ru>
* 
* http://shaparov.ru/
* 
* ---------------------------------------------
* 
* Copyright (C) 2013 Kirill Shaparov
*
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License along
* with this program; if not, write to the Free Software Foundation, Inc.,
* 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
* http://www.gnu.org/copyleft/gpl.html
*
*/
class Cache {
	
    #######################
    ## VARIABLES ##########
    #######################
    
	private   $name = ''							// Имя кэша
			, $url = ''								// Кэшируемая страница
			, $domen = ''							// Домен кэшируемой страницы
			, $lastTag = ''							// Последний просматриваемый тег (для логов)
			, $parentUrl = array()					// Последний полученный ресурс
			, $resource = array()					// Список ресурсов
			, $log = true							// Логировать ошибки
			
			, $quality = 'high'						// Качество кэширования [high, low]
			
			, $log_path = 'cache.log'				// Путь до логов
			, $path = '/cache/';					// Путь до кэша

	
	
	
	
    #######################
    ## METHODS ############
    ####################### 
    
	public function __construct() {
		
		# 1. Создания хранилища кэша, если его еще нет
		if(!is_dir(getenv("DOCUMENT_ROOT").$this->path)) {
            mkdir(getenv("DOCUMENT_ROOT").$this->path);
        }
		
		return true;
	}
	
	
	/**
	* Создать кэш страницы
	* 
	* @param string название кэша
	* @param string ссылка на страницу сайта, которую надо закешировать
	*/
	public function create($name, $url) {		
		
		if(!preg_match("/^[0-9a-z-_]+$/i",$name)) { $this->log("ERROR: ".$this->message(1), $name); return false; } 			// проверим название
		if(!preg_match("|^([^/]*(//)*[^/]+)|i",$url, $match)) { $this->log("ERROR: ".$this->message(2), $url); return false; }		// проверим домен
		
		$this->domen = $match[2];
		$this->name = $name;
		$this->url = $url;
		$this->resource = array();
		
		# 0. Проверяем есть ли уже кэш
		$f = glob(getenv("DOCUMENT_ROOT").$this->path.$this->name.'.*',GLOB_NOSORT);
		if(count($f)) { $this->log("ERROR: ".$this->message(0), $this->path.basename($f[0])); return false; }
		
		# 1. Получить страницу
		list($header, $body) = $this->connect($url);        
		
		
		// Если тело пустое
		if(empty($body)) return false;		
				
		# 2. Определяем кодировку страницы и тип файла
		foreach($header as $v) {
			
			// Тип файла 
			if(!preg_match("/^Content-type: ([^;]+)/i", $v, $match)) continue;
			$ext = $this->getExt($match[1], $url);
			
			// Разновидность типа (text, image)
			$type = explode('/',$match[1]);
			
			switch($type[0]) {
				case 'text':
					
					// папка создается только для определенного типа файлов
					if(in_array($ext,array('html','shtml','htm','xml','css'))) {						
						// создаем папку для дополнительных ресурсов
				        if(!is_dir(getenv("DOCUMENT_ROOT").$this->path.$this->name)) mkdir(getenv("DOCUMENT_ROOT").$this->path.$this->name);        
					}
					
					// Кодировка страницы  
					if(preg_match("/^Content-type: [^;]+; charset=([a-z0-9-]+)/i", $v, $match)) $charset = $match[1];								
				break;
				
				
				case 'image': 
				case 'application':
				default: 
					file_put_contents(getenv("DOCUMENT_ROOT").$this->path.$this->name.'.'.$ext, $body);
					return true;
				break;
			}
			
			break;
		}
		
		// Не определен тип файла
		if(!$ext) { $this->log( "Error: undefined extension", $url ); return false; }
		
		// Не определена кодировка
		if(!$charset) { 
			if(!($charset = mb_detect_encoding($body, array('UTF-8', 'Windows-1251')))) {
				$this->log( "Error: undefined charset", $url ); 
				return false; 
			}			
		}
		
		// Если кодировка не UTF-8, перекодируем контент
		if($charset != 'UTF-8') {
			
			$temp_body = iconv($charset,'UTF-8//TRANSLIT',$body);
			if($temp_body) $body = $temp_body;
			else $body = iconv('Windows-1251','UTF-8//TRANSLIT',$body);
			
			$body = preg_replace_callback("/<meta([^>]+)>/mi", array($this, 'changeCharset'), $body); // меняем кодировку в документе, чтобы правильно отработал domDocument
		}
		
		
		// создаем новый dom-объект
	    $dom = new domDocument;

	    // загружаем html в объект
	    @$dom->loadHTML($body);
	    
		# 3. Узнаем полный базовый адрес документа
		$base = $dom->documentElement->getElementsByTagName('base');
		foreach ($base as $val) {
			
			// Модифицируем путь до файлов
			$this->domen = $val->getAttribute('href');			
			
			// Удаляем последний слэш, по ресурсам это выгодней, такие пути чаще встречаются /img/n.gif			
			$this->domen = preg_replace("|/$|", '', $this->domen);
			
			// Удаляем base
			if($this->quality == 'low') $this->deleteNode($val); 
		}
	    
		# 4. Ссылки (Модифицируем, чтобы относительные адреса не ссылались на кэш)
		if($this->quality == 'low') {
			$a = $dom->getElementsByTagName('a');
			foreach ($a as $i=>$val) {
				if(!$val->getAttribute('href')) continue;
				
				if(!preg_match("|^/|i",$val->getAttribute('href'))) continue; // если начинается со слэша
				
				$val->setAttribute('href',$this->domen.$val->getAttribute('href'));
			}
		}
		
	    # 4. Изображения
	    $img = $dom->getElementsByTagName('img');
		foreach ($img as $i=>$val) {
			$this->lastTag = 'img #'.$i;
			if($val->getAttribute('src')) $val->setAttribute('src',$this->getSource($val->getAttribute('src')));				# обычное изображение
			if($val->getAttribute('data-thumb')) $val->setAttribute('src',$this->getSource($val->getAttribute('data-thumb')));	# youtube.com
		}
		
		# 5. Стили, фавиконка
		$link = $dom->getElementsByTagName('link');
		foreach ($link as $i=>$val) {
			if(!$val->getAttribute('href')) continue;
			$this->lastTag = 'link #'.$i;
			if(in_array($val->getAttribute('rel'), array('shortcut icon','stylesheet'))) $val->setAttribute('href',$this->getSource($val->getAttribute('href')));
		} 
		
		# 7. Объект
		$object = $dom->getElementsByTagName('object');
		foreach ($object as $i=>$val) {
			$this->lastTag = 'object #'.$i;
			if($val->getAttribute('data')) $val->setAttribute('data',$this->getSource($val->getAttribute('data')));
		} 
					
		# 7. Апплеты
		$applet = $dom->getElementsByTagName('applet');
		foreach ($applet as $i=>$val) {
			$this->lastTag = 'applet #'.$i;
			if($val->getAttribute('code')) $val->setAttribute('code',$this->getSource($val->getAttribute('code')));
		} 
				
		# 7. Видео
		$video = $dom->getElementsByTagName('video');
		foreach ($video as $i=>$val) {
			$this->lastTag = 'video #'.$i;
			if($val->getAttribute('poster')) $val->setAttribute('poster',$this->getSource($val->getAttribute('poster')));
			if($val->getAttribute('src')) $val->setAttribute('src',$this->getSource($val->getAttribute('src')));
		} 
			
		# 7. Видео
		$param = $dom->getElementsByTagName('param');
		foreach ($param as $i=>$val) {
			if(!in_array($val->getAttribute('name'),array('movie','base'))) continue;
			if(!$val->getAttribute('value')) continue;
			$this->lastTag = 'param #'.$i;
			$val->setAttribute('value',$this->getSource($val->getAttribute('value')));
		} 
		
		// список тегов из которых надо получить ресурсы		
		$tags = array('audio', 'embed', 'source', 'frame', 'script', 'iframe');		
		
		foreach($tags as $v) {				
			$t = $dom->getElementsByTagName($v);
			foreach ($t as $i=>$val) {
				if(!$val->getAttribute('src')) continue;
				$this->lastTag = $v.' #'.$i;
				$val->setAttribute('src',$this->getSource($val->getAttribute('src')));
			} 
		}
				
				
				
		# 7. Согласно качеству преобразуем объект в строку
		switch($this->quality) {
			case 'high':
				$body = preg_replace("/<base[^>]+>/mi",'',$body); // удаляем base		
			break;
			
			
			case 'low':
				$body = $dom->saveHTML();
			break;
		}
		
		// Получаем ресурсы из css
		$body = $this->getSourceFromCSS($body,$url);
				
		if($this->quality == 'high') {
			// заменяем все ресурсы
			foreach($this->resource as $val) {				
				$body = str_replace(array("'".$val['true-path']."'","\"".$val['true-path']."\""),'"'.$val['new-name'].'"',$body);
			}			
			
			// Меняем относительные ссылки на постоянные, чтобы они вели не на мой сайт
			$body = preg_replace_callback("|(<a[^>]+)|mi", array($this, 'changeHREF'), $body);
			
			// Обрабатываем conditional comments
			$body = preg_replace_callback("/<!--\[if\s(?:[^<]+|<(?!!\[endif\]-->))*<!\[endif\]-->/mi", array($this, 'conditionalComments'), $body);
		}		
			 
		# 10. Сохраняем страницу
		file_put_contents(getenv("DOCUMENT_ROOT").$this->path.$this->name.'.'.$ext, $body);
		
		return true;
	}
	
	
	
	
    #######################
    ## PRIVATE ############
    #######################    
	
	/**
	* Перебор все сторонних ресурсов в теле страницы и выкачка их на сервер
	* 
	* @param string ссылка на ресурс
	* @return string ссылка на локальный ресурс
	*/
	private function getSource($true_url) {		
		
		// Приводим путь к нормальному виду
		if(false === ($url = $this->changeAbsoluteURL($true_url))) return $true_url;
		
		// Старый адрес повторяется
		foreach($this->resource as $v) if($v['old-path'] == $url) return $v['new-name'];
		
		// Получаем ресурс
		list($header, $body) = $this->connect( $url );
		
		// Если тело пустое
		if(empty($body)) return $url;		
				
		// Из заголовков узнаем расширение
		foreach($header as $v) {
			if(preg_match("/^Content-type: (.+)/i", $v, $match)) $ext = $this->getExt($match[1], $url);
		}
		
		// Content-type не определен
		if(empty($ext)) {
			$this->log('ERROR: no Content-type', $url);
			return $url;
		}
		
		// Неизвестный формат
		if(!$ext) return $url;
		
		// Если это стиль, то пытаемся извлечь из него ресурсы
		if(in_array($ext, array('css','htm','html','xml'))) $body = $this->getSourceFromCSS($body,$url);
		
		// Новое название ресурса
		$new_path = $this->path.$this->name.'/'.count($this->resource).'.'.$ext;
		
		// Записываем ресурс в папку
		file_put_contents(getenv("DOCUMENT_ROOT").$new_path, $body);
		
		$this->resource[] = array(
			'true-path' => $true_url,
			'old-path' => $url,
			'new-name' => $new_path
		);
		
		return $new_path;
	}
	
	
	/**
	* Получение ресурсов из css
	* 
	* @param mixed $body
	* @param mixed $url
	* @return mixed
	*/
	private function getSourceFromCSS( $body, $url ) {
		
		/*
		//TODO: 5.3.0 - Появление анонимных функций.
		$callback = function( $matches ) use ( $url ) {
			
			//TODO: 5.4.0 - Стало возможным использовать $this в анонимных функциях.
			$this->getUrlSource($matches, $url);			
	    }; 
		
		$body = preg_replace_callback("|(url)\(([^)]+)\)|mi", $callback, $body);
		$body = preg_replace_callback("|(@import)[ ]*([^ ]+)|mi", $callback, $body);
		*/		
		
		# 1. Запоминаем ссылку не ресурс относительного которого мы будем получать следующие файлы
		array_push($this->parentUrl,$url);
		 
		# 2. Ресурсы с указанным url: img, @import url()		
		$body = preg_replace_callback("|(url)\(([^)]+)\)|mi", array($this, 'getUrlSource'), $body);
		 		 
		# 3. Ресурсы без url: @import		
		$body = preg_replace_callback("|(@import)[ ]*([^ ]+)|mi", array($this, 'getUrlSource'), $body);
		
		# 4. Атрибут <td valign="top" background="images/enter_bg.jpg">		
		$body = preg_replace_callback("|(background)[ ]*=[ ]*\"([^\"]+)|mi", array($this, 'getUrlSource'), $body);
		
		# 5. Удаляем ссылку на ресурс
		array_pop($this->parentUrl);
		
		return $body;
	}
	
	
	
	/**
	* Меняем в документе кодировку на UTF-8 для правильной работы domDocument
	* 
	* @param array 
	* @return string
	*/
	private function changeCharset($p) {
		
		if(!preg_match("/http-equiv=\"Content-Type\"/i",$p[0])) return $p[0];		
		return preg_replace("/charset=([a-z0-9-]+)/i", 'charset=UTF-8', $p[0]);
	}
	
	
	/**
	* Меняем относительные ссылки на постоянные, чтобы они вели не на кэш
	* 
	* @param mixed $p
	* @return mixed
	*/
	private function changeHREF($p) {
		
		if(!preg_match("|(href[ =\"'`]+)([^\"'`]+)/|mi",$p[0],$matches)) return $p[0];
		
		if(false === ($url = $this->changeAbsoluteURL($matches[2]))) return $p[0];
		
		return str_replace($matches[2], $url, $p[0]);
	}
	
	
	/**
	* Сохранение ресурсов из условных комментариев
	* 
	* @param mixed $p
	* @return mixed
	*/
	private function conditionalComments($p) {
		
		# 1. Изображения
		$p[0] = preg_replace_callback("|<(img)([^>]+)|mi", array($this, 'getUrlSource'), $p[0]); 

		# 2. Стили
		$p[0] = preg_replace_callback("|<(link)([^>]+)|mi", array($this, 'getUrlSource'), $p[0]);

		# 3. Джаваскрипт
		$p[0] = preg_replace_callback("|<(script)([^>]+)|mi", array($this, 'getUrlSource'), $p[0]);
		
		return $p[0];
	}	
	
	
	/**
	* Получение ресурса для url() подобия
	* 
	* @param array $p
	* @return string
	*/
	private function getUrlSource($p) {
		
		switch($p[1]) {
			
			# 1. Изображения
			case 'img': 
				if(!preg_match("|src=\"([^\"]+)\"|", $p[2], $m)) return $p[0];
				$url = $m[1];
			break;
			
			# 2. Стили
			case 'link': 
				if(!preg_match("|href=\"([^\"]+)\"|", $p[2], $m)) return $p[0];
				$url = $m[1];
			break;
			
			# 3. Джаваскрипт
			case 'script': 
				if(!preg_match("|src=\"([^\"]+)\"|", $p[2], $m)) return $p[0];
				$url = $m[1];
			break;
			
			# 1. Ресурсы внутри css
			case 'url':
				$this->lastTag = 'style url()';
				$url = $p[2];				
			break;
			
			# 2. Подключаемые ресурсы без url()
			case '@import':
				if(preg_match("/^url\(/",$p[2])) return $p[0]; // Это все-таки url()
				$this->lastTag = 'style @import';
				$url = $p[2];
			break;
			
			default:
				$url = $p[2];
			break;
		}
		
		$url = str_replace(array("'","\"","`"),'',$url); // избавляемся от кавычек
		
		return str_replace($url, $this->getSource($url), $p[0]);
	}
	
	
	
	/**
	* Заменяем относительный путь на абсолютный
	* 
	* @param string путь
	*/
	private function changeAbsoluteURL( $url ) {
		
		// Повторяющиеся урлы не проверяем
		foreach($this->resource as $v) if($v['new-name'] == $url) return false;		
		
		if(preg_match("/^data:/", $url)) return false; 						# кодированное изображение								data:image/png;base64
		elseif(preg_match("/^#/", $url)) return false; 						# хэштек, цвет											#load
		elseif(preg_match("|^//|",$url)) return substr($url,2);				# путь относительно схемы								//yandex.ru/img/i.png
		elseif(preg_match("|^/|",$url))  return $this->domen.$url;			# путь локальный										/includes/templates/tehnostudio_ru/js/PIE.htc
		elseif(preg_match("|^[a-z0-9]+://|i",$url)) return $url; 			# путь абсолютный										http://tehnostudio.ru/includes/templates/tehnostudio_ru/js/PIE.htc
		elseif(preg_match("|^[^./]+|",$url)) {								# путь относительно корня сайта							iepngfix.htc
			if(count($this->parentUrl)){				
				
				if(!preg_match("|^([^/]*//[^/]+)|i",$this->array_last($this->parentUrl), $match)) { $this->log('ERROR: no url', 'Источник: '.$this->array_last($this->parentUrl).'; Адрес: '.($url?$url:'нет').';'); return false; }		// получаем домен
				
				return $match[0].'/'.$url;
			} 
			else {				
				return $this->domen.'/'.$url;
			} 
		}
		elseif(preg_match("|^\.\.|",$url)) {								# путь относительно папки								../im/m.png
				
			// кол-во многоточий
			$arr_url = explode('/',$url);
			
			$level = '/[^/]*';
			foreach($arr_url as $u) if($u == '..') $level.='/[^/]*';
			
			return preg_replace("|".$level."$|","/",$this->array_last($this->parentUrl)).str_replace("../",'',$url);
		}
		
		// не знаем как преобразовать данный адрес, записываем в лог файл
		if(count($this->parentUrl)) $this->log('ERROR: no url', 'Источник: '.$this->array_last($this->parentUrl).'; Адрес: '.($url?$url:'нет').';');
		else $this->log('ERROR: no url', 'Источник: '.$this->name.'; Тег: '.$this->lastTag.'; Адрес: '.($url?$url:'нет').';');
		
		return false;
	}
	
	/**
	* Возвращает значение последнего элемента массива
	* 
	* @param array массив
	*/
	private function array_last($arr) {
		return $arr[count($arr)-1];
	}
	
	/**
	* Удаление узла из DOM
	* 
	* @param mixed $node
	*/
	private function deleteNode($node) {
	    $this->deleteChildren($node);
	    $parent = $node->parentNode;
	    $oldnode = $parent->removeChild($node);
	} 
	
	
	/**
	* Рекурсивное удаление всех детей из DOM
	* 
	* @param mixed $node
	*/
	private function deleteChildren($node) {
	    while (isset($node->firstChild)) {
	        $this->deleteChildren($node->firstChild);
	        $node->removeChild($node->firstChild);
	    }
	}	
	
	
	/**
	* Получить расширение файла по его заголовку
	* 
	* @param string заголовок
	*/
	private function getExt($type, $url) {
		$arr_type = explode(';', strtolower($type));
		switch($arr_type[0]) {
			case 'text/css': $ext = 'css'; break;
			case 'image/x-icon': $ext = 'ico'; break;
			case 'image/gif': $ext = 'gif'; break;
			case 'image/png': $ext = 'png'; break;
			case 'image/svg':
			case 'image/svg+xml': $ext = 'svg'; break;
			case 'image/jpeg': $ext = 'jpeg'; break;
			case 'image/jpg': $ext = 'jpg'; break;
			case 'text/html': $ext = 'html'; break;
			case 'text/xml': $ext = 'xml'; break;
			case 'application/javascript': 
			case 'text/javascript': 
			case 'application/x-javascript': $ext = 'js'; break;
			case 'application/x-shockwave-flash': $ext = 'swf'; break;
			
			default:
			
				$f = explode('.',basename($url));
				switch($f[0]) {
					case 'htc': $ext = 'htc'; break;
					default:
						$this->log('ERROR: undefined type', 'content-type: '.$type.' -- url:'.$url);
						return $f[0];
					break;
				}
			break;
		}
		return $ext;
	}
	
	
	/**
	* Подключение к ресурсу и получение его содержимого
	* 
	* @param string путь до ресурса
	* @return array(список заголовков, тело ресурса)
	*/
	private function connect($url) {
		
		$options = array(
			  CURLOPT_SSL_VERIFYPEER => false
			, CURLOPT_URL => $url					# return web page
			, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0 # используем старую версию протокола, чтобы правильно разбивать заголовки
			, CURLOPT_RETURNTRANSFER => true		# return web page
			, CURLOPT_HEADER         => true		# don't return headers
			, CURLOPT_FOLLOWLOCATION => true		# follow redirects
			, CURLOPT_ENCODING       => ""			# handle all encodings
			, CURLOPT_USERAGENT      => "spider"	# who am i
			, CURLOPT_AUTOREFERER    => true		# set referer on redirect
			, CURLOPT_CONNECTTIMEOUT => 120			# timeout on connect
			, CURLOPT_TIMEOUT        => 120			# timeout on response
			, CURLOPT_MAXREDIRS      => 10			# stop after 10 redirects
		);
		
		
		$ch = curl_init();							# инициализируем отдельное соединение (поток)
		curl_setopt_array( $ch, $options );			# применяем параметры запроса
		$page = curl_exec($ch); 					# выполняем запрос
		$info = curl_getinfo($ch);					# информация об ответе
		$info['error_num'] = curl_errno($ch);		# код ошибки
		$info['error_txt'] = curl_error($ch);		# текст ошибки
		curl_close($ch);							# закрытие потока
		
		
		if(!$this->connect_error($url,$info)) return array();	# проверяем соединение на ошибки
		
		
		$header = substr($page, 0, $info['header_size']);	# отделяем заголовки от контента
		$header = explode("\r\n\r\n", $header);				# разбиваем заголовки по блокам
		$header = $header[$info['redirect_count']];			# оставляем последний блок заголовков
		$header = explode("\r\n", $header);					# получаем список заголовков
		
		$body = substr($page, $info['header_size']);		# контент
		
		
		return array($header, $body, $info['content_type']);
	}
	
	
	/**
	* Протолирование ошибок соединения
	* 
	* @param string путь до ресурса
	* @param array информация о соединении
	*/
	private function connect_error($url,$info) {
		
		// Коды ответа
		switch($info['http_code']) {
			
			# Страница не найдена
			case 404: 
				$this->log('ERROR: curl, '.$info['http_code'].': Страница не найдена', $url);
				return false;
			break;
			
			# Неправильный путь
			case 512: 
				$this->log('ERROR: curl, '.$info['http_code'].': Bad Gateway', $url);
				return false;
			break;
			
			# Проверяем на ошибки
			default:
				if(!$info['error_num']) return true;
				$this->log('ERROR: curl, '.$info['error_num'].': '.$info['error_txt'], $url);
				return false;
			break;
		}
		
		return true;
	}
	
	
	/**
	* Логирование
	* 
	* @param string тип лога
	* @param string значение
	*/
	private function log( $type, $value ) {
		
		// нужно ли логирование
		if(!$this->log) return false;
		
		$log = date("Y-m-d H:i:s").' - '.$type.' - '.$value."\r\n";
		
		// Открываем файл
		$d = fopen(getenv("DOCUMENT_ROOT").'/'.$this->log_path,"a+");
		
		if(!$d) return false;
		
		fwrite ( $d, $log );
		
		fclose($d);
		
		return true;		
	}
	
	
	/**
	* Сообщения
	* 
	* @param mixed $num
	*/
	private function message( $num ) {
		switch($num) {
			case 0: return "Кэш уже существует";
			case 1: return "Неправильное имя для кэша";
			case 2: return "Не удалось определить домен кэшируемой страницы";
			default: return "Неизвестная ошибка";
		}
	}
}

?>
