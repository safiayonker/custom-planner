<?php

require_once 'Zend/Loader.php';
Zend_Loader::loadClass('Zend_Gdata');
Zend_Loader::loadClass('Zend_Gdata_HttpClient');
Zend_Loader::loadClass('Zend_Gdata_Calendar');

$dayinseconds = 24 * 60 * 60;

function getFeeds($startdate, $enddate) {
    global $dayinseconds;
    $feeds = array();
    $calendars = array(
        'facultyadmin@communityhigh.net',
        'en.usa%23holiday@group.v.calendar.google.com',
        'communityhigh.net_cqt4f59nci2gvtftuqseal396o%40group.calendar.google.com',
        'communityhigh.net_5d3b9b97gj76hqmk9ir4s2usm8%40group.calendar.google.com'
    );
    foreach ($calendars as $user) {
        /* @var $service Zend_Gdata_Calendar */
        $service = new Zend_Gdata_Calendar();

        /* @var $query Zend_Gdata_Calendar_EventQuery */
        $query = $service->newEventQuery();
        $query->setUser($user);
        $query->setOrderby('starttime');
        $query->setSortOrder('ascending');
        $query->setMaxResults(100000);

        //get days events: event: name, description
        $query->setStartMin(date(DATE_RFC3339, $startdate));
        $query->setStartMax(date(DATE_RFC3339, $enddate + 6 * $dayinseconds));

        try {
            $feeds[] = $service->getCalendarEventFeed($query);
            //foreach($eventFeed as $event)
            // var_dump($event->when);
        } catch (Zend_Gdata_App_Exception $e) {
            echo "Error: " . $e->getMessage();
        }
    }
    return $feeds;
}

function printPages($startdate, $enddate) {
    global $dayinseconds;

    //start monday before start date
    $currentdate = $startdate - (date('N', $startdate) - 1) * $dayinseconds;
    global $eventFeed, $service, $holidayFeed;
    date_default_timezone_set('America/New_York');

    $current_month = date('m', $currentdate);
    $feeds = getFeeds($currentdate, $enddate);

    $data_xml = "<PlannerData>";

//week loop:
    while (!($currentdate > $enddate)) {
        $data_xml .= "<Month>";
        $data_xml .= "<PreviousMonth>" . getMonthXml(strtotime(date('c', $currentdate) . ' - 1 month')) . "</PreviousMonth>";
        $data_xml .= "<NextMonth>" . getMonthXml(strtotime(date('c', $currentdate) . ' + 1 month')) . "</NextMonth>";
        $data_xml .= getMonthXml($currentdate);


        while (date('m', $currentdate) == $current_month) {
            $data_xml .= "<Week>";
            $ongoing_events = '';
            for ($i = 0; $i < 7; $i++) {
                $data_xml .= "<Day id=\"" . date('N', $currentdate) . "\">";
                $data_xml .= "<ShortName>" . date('D', $currentdate) . "</ShortName>";
                $data_xml .= "<Month>" . date('F', $currentdate) . "</Month>";
                $data_xml .= "<Date>" . date('j', $currentdate) . "</Date>";
//get event list
                $event_list = '';
                foreach ($feeds as $feed) {
                    $lists = getEventList($currentdate, $feed);
                    $ongoing_events .= $lists['ongoing_events'];
                    $event_list .= $lists['event_list'];
                }
                $data_xml .= ($event_list) ? '<EventList>' . $event_list . '</EventList>' : '';

                $data_xml .= "</Day>";
                $currentdate = strtotime(date('Y-m-d', $currentdate) . ' + 1 day');
            }
            $data_xml .= ($ongoing_events) ? '<OngoingEvents>' . $ongoing_events . '</OngoingEvents>' : '';
            $data_xml .= "</Week>";
        }
        $data_xml .= '</Month>';
        $current_month = date('m', $currentdate);
    }
    $data_xml .= '</PlannerData>';
    echo $data_xml;
}

function getEventList($currentdate, $feed) {
    $event_xml = '';
    $ongoing_xml = '';

    foreach ($feed as $event) {
        $when = $event->getWhen();
        //events that start today
        if (date('Y-m-d', strtotime($when[0]->getStartTime())) > date('Y-m-d',$currentdate)) { //no need to look anymore
            break;
        } elseif (date('Y-m-d', strtotime($when[0]->getStartTime())) == date('Y-m-d', $currentdate)) {
            $content = '';
            $title = '';
            //only today
            if (date('Y-m-d', strtotime($when[0]->getStartTime())) == date('Y-m-d', strtotime($when[0]->getEndTime()))) {
                $content = date('g:ia', strtotime($when[0]->getStartTime())) . "-";
                $content .= date('g:ia', strtotime($when[0]->getEndTime())) . " ";
            }
            //multiday events
            if (strtotime($when[0]->getEndTime()) > strtotime($when[0]->getStartTime() . ' + 1 day')) {
                //add to ongoing
                $title = $event->title;
                $content = '(' . date('n/j', strtotime($when[0]->getStartTime())) . '-' . date('n/j', strtotime($when[0]->getEndTime() . ' -1 day')) . ')';
                $ongoing_xml .= getEventXml($title, $content);

                $title = 'First day: ';
                $content = '';
            }
            $title .= $event->title;
            $content .= $event->content;

            $event_xml .= getEventXml($title, $content);
            //google cal: endTime is one day after last day
        } elseif (date('Y-m-d', strtotime($when[0]->getEndTime() . ' - 1 day')) == date('Y-m-d', $currentdate)) { //events that end today (but don't start today)
            $title = 'Last day: ' . $event->title;
            $content = $event->content;
            $event_xml .= getEventXml($title, $content);
        } elseif (date('N', $currentdate) == 1 && (strtotime($when[0]->getStartTime()) < $currentdate && $currentdate < strtotime($when[0]->getEndTime()))) {
            $title = $event->title;
            $content = '(' . date('n/j', strtotime($when[0]->getStartTime())) . '-' . date('n/j', strtotime($when[0]->getEndTime() . ' -1 day')) . ')';
            $ongoing_xml .= getEventXml($title, $content);
        }
    }

    return array('event_list' => $event_xml, 'ongoing_events' => $ongoing_xml);
}

function getMonthXml($currentdate) {
    $data_xml = "<Name>" . date('F', $currentdate) . "</Name>";
    $data_xml .= "<ShortName>" . date('M', $currentdate) . "</ShortName>";
    $data_xml .= "<NumDays>" . date('t', $currentdate) . "</NumDays>";
    $data_xml .= "<FirstDay>" . date('w', strtotime(date('M', $currentdate) . ' 1, ' . date('Y', $currentdate))) . "</FirstDay>";
    return $data_xml;
}

function getEventXml($title, $content) {
    $event_xml = '<Event>';
    $event_xml .= '<Title>' . $title . '</Title>';
    $event_xml .= '<Content>' . $content . '</Content>';
    $event_xml .= '</Event>';
    return $event_xml;
}
?>
