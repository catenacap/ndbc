<?php


if(!file_exists($_SERVER['DOCUMENT_ROOT'] . "/ndbc/stationHistoryDataCronLog.txt")){
	
	file_put_contents('stationHistoryDataCronLog.txt', "running\n", FILE_APPEND);

	include('config.php');
	include('simple_html_dom.php');
	
	@ini_set("output_buffering", "Off");
	@ini_set('implicit_flush', 1);
	@ini_set('zlib.output_compression', 0);
	@ini_set('max_execution_time', 48000);
	
	$station_info = array();
	
	$selectDetailURLS = $db->query('SELECT * FROM `ndbc_station_ids` ORDER BY `id` ASC');
	
	$total_pages = $selectDetailURLS->num_rows;
	$instances = 20;
	$chunks = ceil($total_pages / $instances);
	
	$i = 0;
	while($detailURLS = $selectDetailURLS->fetch_array(MYSQLI_ASSOC)){
		
		$station_info[$i]['station_id'] = $detailURLS['station_id'];
		$station_info[$i]['station_detail_url'] = $detailURLS['station_detail_url'];
		
		$i++;
		
	}
	
	start_execution($total_pages, $instances, $chunks, $station_info);

	unlink('stationHistoryDataCronLog.txt');
	
}

function start_execution($total_pages, $instances, $chunks, $station_info){
	
    //var_dump($total_pages, $instances, $chunks); exit;
    
    for($chk = 1; $chk <= $chunks; $chk++){
    	
      $urls = array();
      $start = (($chk - 1) * $instances) + 1;
      
      if($chk == 1){
      	$end = $chk * $instances;
      } else if($chk == $chunks){
        $end = $total_pages;
      } else {
        $end = $chk * $instances;
      }

      for($page = $start; $page <= $end; $page++){
        $urls[] = $station_info[$page]['station_detail_url'];
        $stations[] = $station_info[$page]['station_id'];
      }
      
      //echo "<pre>"; print_r($urls); exit;
      
      // send multiple request at the same time.
      multiRequest($urls, $chk, $stations);
      
      unset($urls);
      
    }
}

function multiRequest($data, $iteration, $stations, $options = array()) {
  // array of curl handles
  $curly = array();
  // data to be returned
  $result = array();
  
  // Generate a random variable.
  ${"data".$iteration} = $data;
  $station_urls = $data;
  unset($data);
  
  // multi handle
  $mh = curl_multi_init();
  // loop through $data and create curl handles
  // then add them to the multi-handle
  foreach (${"data".$iteration} as $id => $d) {
  	
    $curly[$id] = curl_init();
    $url = (is_array($d) && !empty($d['url'])) ? $d['url'] : $d;
    curl_setopt($curly[$id], CURLOPT_URL, $url);
    curl_setopt($curly[$id], CURLOPT_HEADER, 0);
    curl_setopt($curly[$id], CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curly[$id], CURLOPT_TIMEOUT, 40);
		curl_setopt($curly[$id], CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curly[$id], CURLOPT_SSL_VERIFYHOST, false);
		
    // post?
    if (is_array($d)) {
      if (!empty($d['post'])) {
        curl_setopt($curly[$id], CURLOPT_POST,       1);
        curl_setopt($curly[$id], CURLOPT_POSTFIELDS, $d['post']);
      }
    }
    
    // extra options?
    if (!empty($options)) {
      curl_setopt_array($curly[$id], $options);
    }
    
    curl_multi_add_handle($mh, $curly[$id]);
    
  }
  
  // execute the handles
  $running = null;
  do {
      curl_multi_exec($mh, $running);
  } while($running > 0);
  
  // get content and remove handles
  foreach($curly as $id => $c) {
      $result[$id] = curl_multi_getcontent($c);
      $station_ids[$id] = $stations[$id];
      $stationurls[$id] = $station_urls[$id];
      curl_multi_remove_handle($mh, $c);
  }
  
  // all done
  curl_multi_close($mh);
  
  // Extrat the data.
  foreach($result as $key => $ndbcDetailData){
  	
  	extract_data($ndbcDetailData, $station_ids[$key], $stationurls[$key]);
  	
  }
  
  // clear the resources.
  unset($result);
  unset($vessel);
  unset(${"data".$iteration});    
}

function extract_data($data, $station_id, $station_url){
	
	global $db;
	
	$html = str_get_html($data);
	
	$detailInfo = array();
	
	$detailInfo['station_id'] = $station_id;
	//$detailInfo['station_url'] = $station_url;
	
	unset($data);
	
	if($html && is_object($html) && isset($html->nodes)){
			
		if($html->find("#contenttable table p", 0)){
			
			$content = $html->find("#contenttable table p", 0)->innertext;
			
			$myArray = preg_split('/<b>/i', $content);
			
			foreach($myArray as $foundedData){
				
				if(strpos($foundedData, '(') !== false && strpos($foundedData, ')') !== false){
					
					$splitDegrees = explode(' (', $foundedData);
					$convertLatLong = explode(' ', $splitDegrees[0]);
					
					$detailInfo['station_latitude'] = trim($convertLatLong[0] . ' ' . $convertLatLong[1]);
					$detailInfo['station_longitude'] = trim($convertLatLong[2] . ' ' . $convertLatLong[3]);
					
				}
				
				if(strpos($foundedData, ':') !== false){
					
					$splitDegrees = explode(':', $foundedData);
					
					$detailInfo[strtolower(str_replace(' ', '_', $splitDegrees[0]))] = trim(strip_tags($splitDegrees[1]));
					
				}
				
			}
			
			$dbColumns = array();
			$dbColumnValues = array();
			$columnsKey = array_keys($detailInfo);
			$dbCreateColumn = '';
			$dbWhereQuery = '';
			
			foreach($columnsKey as $column){
				
				$dbColumns[] = '`' . $column . '`';
				
				if($column == 'station_id'){
					$dbCreateColumn .= '`' . $column . '` VARCHAR(20) NULL,';
				} else if($column == 'station_latitude'){
					$dbCreateColumn .= '`' . $column . '` VARCHAR(30) NULL,';
				} else if($column == 'station_longitude'){
					$dbCreateColumn .= '`' . $column . '` VARCHAR(30) NULL,';
				} else {
					$dbCreateColumn .= '`' . $column . '` VARCHAR(500) NULL,';
				}
				
			}
			
			foreach($detailInfo as $key => $value){
				
				$dbWhereQuery .= ' AND `' . $key . '` = "' . $db->real_escape_string($value) . '"';
				
				$dbColumnValues[] = '"' . $db->real_escape_string($value) . '"';
				
			}
			
			// Check for new columns
			$duplicateColumns = $db->query('SELECT column_name FROM information_schema.columns WHERE table_name = "ndbc_station_' . $detailInfo['station_id'] . '"');
			$duplicateColumnArray = array();
			
			if($duplicateColumns->num_rows > 0){
				
				while($row = $duplicateColumns->fetch_array(MYSQLI_ASSOC)){
					$duplicateColumnArray[] = '`' . $row['column_name'] . '`';
				}
				
				array_shift($duplicateColumnArray);
				
				foreach($dbColumns as $key => $duplicateColumn){
					
					if(!in_array($duplicateColumn, $duplicateColumnArray)){
						
						$db->query('ALTER TABLE `ndbc_station_' . $detailInfo['station_id'] . '` ADD COLUMN ' . $duplicateColumn . ' VARCHAR(500) NULL');
						
					}
					
				}
				
			}
			
			$db->query('CREATE TABLE IF NOT EXISTS `ndbc_station_' . $detailInfo['station_id'] . '` (
								  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
								  `created_date` VARCHAR(30) NOT NULL,
								  ' . $dbCreateColumn . '
								  PRIMARY KEY (`id`)
								) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8');
			
			$selectQuery = $db->query('SELECT * FROM `ndbc_station_' . $detailInfo['station_id'] . '` WHERE 1 = 1' . $dbWhereQuery);

			if($selectQuery->num_rows == 0){
				
				$db->query('INSERT INTO `ndbc_station_' . $detailInfo['station_id'] . '` (`created_date`, ' . implode(', ', $dbColumns) . ') VALUES ("' . date('Y-m-d H:i:s') . '", ' . implode(', ', $dbColumnValues) . ')');
				
			}

		}
		
	}
	
}
 
?>