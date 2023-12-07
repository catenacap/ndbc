<?php


if(!file_exists($_SERVER['DOCUMENT_ROOT'] . "/ndbc/stationsDataCronLog.txt")){
	
	file_put_contents('stationsDataCronLog.txt', "running\n", FILE_APPEND);
	
	include('config.php');
	include('simple_html_dom.php');
	
	@ini_set("output_buffering", "Off");
	@ini_set('implicit_flush', 1);
	@ini_set('zlib.output_compression', 0);
	@ini_set('max_execution_time', 48000);
	
	$station_info = array();
	
	$html = file_get_html('http://www.ndbc.noaa.gov/to_station.shtml');
	
	if($html && is_object($html) && isset($html->nodes)){
				
		if($html->find("#contenttable", 0)){
			
			$i = 0;
			
			foreach($html->find("#contenttable", 0)->find('pre') as $preData){
				
				foreach($preData->find('a') as $stationInfo){
					
					$station_id = trim($stationInfo->plaintext);
					$station_detail_url = trim('http://www.ndbc.noaa.gov/' . $stationInfo->getAttribute('href'));
					
					$station_info[$i]['station_id'] = $station_id;
					$station_info[$i]['station_detail_url'] = $station_detail_url;
					
					$i++;
					
				}
				
			}
			
			$total_pages = count($station_info);
			$instances = 20;
			$chunks = ceil($total_pages / $instances);
			
			start_execution($total_pages, $instances, $chunks, $station_info);
			
		}
		
	}
	
	unlink('stationsDataCronLog.txt');
	
}

function start_execution($total_pages, $instances, $chunks, $station_info){
	
    //var_dump($total_pages, $instances, $chunks); exit;
    
    for($chk = 1; $chk <= $chunks; $chk++){
    	
      $urls = array();
      $stations = array();
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
      $stations_data[$id] = $stations[$id];
      $stations_url_data[$id] = $station_urls[$id];
      curl_multi_remove_handle($mh, $c);
  }
  
  // all done
  curl_multi_close($mh);
  
  // Extrat the data.
  foreach($result as $key => $ndbcDetailData){
  	
  	extract_data($ndbcDetailData, $stations_data[$key], $stations_url_data[$key]);
  	
  }
  
  // clear the resources.
  unset($result);
  unset($vessel);
  unset(${"data".$iteration});    
}

function extract_data($data, $station_id, $station_url){
	
	global $db;
	
	$html = str_get_html($data);
	
	unset($data);
	
	if($html && is_object($html) && isset($html->nodes)){
			
		if($html->find("#contenttable h1", 0)){
			
			$selectStation = $db->query('SELECT * FROM `ndbc_station_ids` WHERE `station_id` = "' . $station_id . '"');
			
			if($selectStation->num_rows == 0){
				
				$db->query('INSERT INTO `ndbc_station_ids` (`station_id`, `station_detail_url`, `station_name`) VALUES ("' . $db->real_escape_string($station_id) . '", "' . $db->real_escape_string($station_url) . '", "' . $db->real_escape_string(trim($html->find("#contenttable h1", 0)->plaintext)) . '")');
				
			} else {
				
				$db->query('UPDATE `ndbc_station_ids` SET `station_detail_url` = "' . $db->real_escape_string($station_url) . '", `station_name` = "' . $db->real_escape_string(trim($html->find("#contenttable h1", 0)->plaintext)) . '" WHERE `station_id` = "' . $db->real_escape_string($station_id) . '"');
				
			}
			
		}
		
	}
	
}

?>