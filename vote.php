<?php

###
# List Votes
# 
# PURPOSE
# List how legislators voted on this particular vote.
# 
# NOTES
# None.
# 
# TODO
# None.
# 
###

# INCLUDES
# Include any files or libraries that are necessary for this specific
# page to function.
include_once('includes/settings.inc.php');
include_once('includes/functions.inc.php');

# DECLARATIVE FUNCTIONS
# Run those functions that are necessary prior to loading this specific
# page.
@connect_to_db();

# INITIALIZE SESSION
session_start();

# LOCALIZE AND CLEAN UP VARIABLES
if (isset($_GET['lis_id']))
{
	$lis_id = strtoupper($_GET['lis_id']);
}
else
{
	die();
}
if (isset($_GET['year']))
{
	$year = $_GET['year'];
}
if (isset($_GET['bill']))
{
	$bill = $_GET['bill'];
}

$html_head = '';

# PAGE METADATA
$page_title = 'Vote';
$site_section = '';	

# PAGE CONTENT

# Select information about this bill.
$sql = 'SELECT bills.id, bills.number, bills.session_id, bills.chamber, bills.catch_line,
		bills.chief_patron_id, bills.summary, bills.notes, representatives.name AS patron,
		districts.number AS patron_district, sessions.year, bills_status.status AS status_line,
		bills_status.translation AS status_line_translation,
		DATE_FORMAT("%m/%d/%Y", bills_status.date) AS status_line_date,
		representatives.party AS patron_party, representatives.chamber AS patron_chamber,
		representatives.shortname AS patron_shortname, committees.name AS committee_name,
		committees.shortname AS committee_shortname
		FROM bills
		LEFT JOIN votes
			ON bills.id=votes.bill_id
		LEFT JOIN sessions
			ON sessions.id=bills.session_id
		LEFT JOIN representatives
			ON representatives.id=bills.chief_patron_id
		LEFT JOIN districts
			ON representatives.district_id=districts.id
		LEFT JOIN bills_status
			ON bills_status.lis_vote_id=votes.lis_id
		LEFT JOIN committees
			ON votes.committee_id=committees.id
		WHERE bills.number="'.$bill.'" AND sessions.year='.$year;
$result = @mysql_query($sql);
if (@mysql_num_rows($result) > 0)
{
	$bill = @mysql_fetch_array($result);
	$bill = array_map('stripslashes', $bill);
	$bill = array_map('trim', $bill);
}

# Now select information about this vote.
$sql = 'SELECT chamber, outcome, tally
		FROM votes
		WHERE lis_id="'.$lis_id.'" AND session_id='.$bill['session_id'];
$result = @mysql_query($sql);
if (@mysql_num_rows($result) == 0)
{
	die('No such vote found.');
}

$vote = @mysql_fetch_array($result);
$vote = array_map('stripslashes', $vote);
$vote = array_map('trim', $vote);

$page_title = strtoupper($bill['number']).': '.$bill['catch_line'];
$page_body = '<p>';
if (!empty($bill['committee_name']))
{
	$page_body .= 'This vote on <a href="/bill/'.$year.'/'.$bill['number'].'/">'.strtoupper($bill['number']).'</a>
	was held in the <a href="/committee/'.$vote['chamber'].'/'.$bill['committee_shortname'].'/">'.ucfirst($vote['chamber']).' 
	'.$bill['committee_name'].'</a> committee.  ';
}
else
{
	$page_body .= 'This vote on <a href="/bill/'.$year.'/'.$bill['number'].'/">'
		.strtoupper($bill['number']).'</a> was held in the '.ucfirst($vote['chamber']).'.  ';
}
$page_body .= 'This vote '.$vote['outcome'].'ed '.$vote['tally'].'.</p>';

# Select every legislator who voted, along with how they voted.
// The following bit was commented out of the WHERE portion of this query:
//
// AND votes.session_id='.$bill['session_id'].'
//
// When bills survive until the following session, and then are voted on anew, they're odd,
// because they exist twice in Richmond Sunlight. So we can't make the query unique by session
// ID. OTOH, if LIS vote IDs aren't unique, this may prove to be problematic.
$sql = 'SELECT representatives.name, representatives.shortname,
		representatives_votes.vote, representatives.party,
		representatives.chamber, representatives.address_district AS address,
		DATE_FORMAT(representatives.date_started, "%Y") AS started,
		districts.number AS district
		FROM votes
		LEFT JOIN representatives_votes
			ON votes.id = representatives_votes.vote_id
		LEFT JOIN representatives
			ON representatives_votes.representative_id = representatives.id
		LEFT JOIN districts
			ON representatives.district_id=districts.id
		LEFT JOIN sessions
			ON votes.session_id=sessions.id
		WHERE votes.lis_id="'.$lis_id.'" AND sessions.year="'.$year.'"
		ORDER BY vote ASC, name ASC';
$result = @mysql_query($sql);
if (@mysql_num_rows($result) > 0)
{
	# Store all of the resulting data in an array, since we have to reuse it a couple of times.
	while ($legislator = @mysql_fetch_array($result))
	{
		$tmp[] = $legislator;
	}
	$legislators = $tmp;
	unset($tmp);
	
	# Step through the legislators data to establish which party voted which way, building up
	# an array of data.
	foreach ($legislators as $legislator)
	{
		$legislator['vote'] = strtolower($legislator['vote']);
		$legislator['party'] = strtolower($legislator['party']);
		$graph[$legislator{vote}][$legislator{party}]++;
		$parties[$legislator{party}] = 1;
	}
	
	# Make sure that we don't have any missing data, party-wise. That is, Google gets sad if
	# we list Democrats, Republicans, and Independents for a "yes" vote, but only Democrats
	# and Republicans for a "no" vote. So if any Independents voted anywhere, we need to make
	# sure that they're listed everywhere, with a "0".
	foreach ($parties as $party => $blargh)
	{
		foreach ($graph as &$vote)
		{
			if (!isset($vote[$party]))
			{
				$vote[$party] = 0;
			}
		}
	}
	
	# Sort our parties in the same order. Otherwise they won't match up.
	if (isset($graph['y']))
	{
		ksort($graph['y']);
	}
	if (isset($graph['n']))
	{
		ksort($graph['n']);
	}
	if (isset($graph['x']))
	{
		ksort($graph['x']);
	}
	if (isset($graph['a']))
	{
		ksort($graph['a']);
	}

	# Again, sort our parties in the same order.
	ksort($parties);
	
	# Only bother displaying a graph if this vote wasn't unanimous. (Most votes are unanimous,
	# so this is a real time-saver.)
	if (count($graph) > 1)
	{
		$html_head .= '
		<script type="text/javascript">
			google.load("visualization", "1", {packages:["corechart"]});
			google.setOnLoadCallback(drawChart);
			function drawChart() {
				var data = new google.visualization.DataTable();
				data.addColumn("string", "Vote");';
		foreach($parties as $party => $blargh)
		{
			if ($party == 'r') $party = 'Rep.';
			elseif ($party == 'd') $party = 'Dem.';
			elseif ($party == 'i') $party = 'Ind.';
			$html_head .= '
				data.addColumn("number", "'.$party.'");';
		}
		$html_head .= '
				data.addRows('.count($graph).');';
		$i=0;
			
		foreach($graph as $outcome => $tally)
		{
			
			if ($outcome == 'y')
			{
				$outcome = 'Voted Yes';
			}
			elseif ($outcome == 'n')
			{
				$outcome = 'Voted No';
			}
			elseif ($outcome == 'x')
			{
				$outcome = 'Didn\'t Vote';
			}
			elseif ($outcome == 'a')
			{
				$outcome = 'Abstained';
			}
			
			$html_head .= '
					data.setValue('.$i.', 0, "'.$outcome.'");';
			$j=1;
			
			foreach ($tally as $party => $count)
			{
				$html_head .= '
					data.setValue('.$i.', '.$j.', '.$count.');';
				$j++;
			}
			$i++;
		}
		$html_head .= '
				var chart = new google.visualization.ColumnChart(document.getElementById("chart"));
				chart.draw(data, {isStacked: true, width: 400, height: 240,';
			
		# Specify the three colors that will color our graph, that correlate (alphabetically)
		# to Democrats, independents, and Republicans.
		if (count($parties) == 3)
		{
			$html_head .= '
				colors:["blue", "green", "red"]});';
		}
		# Unless no independents voted, in which case we just want to define colors for Democrats
		# and Republicans.
		else
		{
			$html_head .= '
				colors:["blue", "red"]});';	
		}
		$html_head .= '
			}
		</script>';
		$page_body .= '<div id="chart"></div>';
	}
	
	# Display the actual vote results.
	$page_body .= '<div>';
	foreach ($legislators as $legislator)
	{
		if (!isset($vote))
		{
			$vote = $legislator['vote'];
			if ($vote == 'Y') $display_vote = 'Yes';
			elseif ($vote == 'N') $display_vote = 'No';
			elseif ($vote == 'X') $display_vote = 'Didn’t Vote';
			elseif ($vote == 'A') $display_vote = 'Abstain';
			$page_body .= '<h2>'.$display_vote.'</h2>
			<ul>';
		}
		elseif ($vote != $legislator['vote'])
		{
			$vote = $legislator['vote'];
			if ($vote == 'Y') $display_vote = 'Yes';
			elseif ($vote == 'N') $display_vote = 'No';
			elseif ($vote == 'X') $display_vote = 'Didn’t Vote';
			elseif ($vote == 'A') $display_vote = 'Abstain';
			$page_body .= '
			</ul>
				<h2>'.$display_vote.'</h2>
			<ul>';
		}
		$legislator = array_map('stripslashes', $legislator);
		$legislator['patron'] = $legislator['name'];
		$legislator['patron_suffix'] = '('.$legislator['party'].'-'.$legislator['district'].')';
		$legislator['patron_chamber'] = $legislator['chamber'];
		$legislator['patron_started'] = $legislator['started'];
		$legislator['patron_address'] = $legislator['address'];
		$legislator['patron_shortname'] = $legislator['shortname'];
		
		$page_body .= '
				<li><a href="/legislator/'.$legislator['shortname'].'/" class="balloon">'.pivot($legislator['name']).
				 balloon($legislator, 'legislator').' '.$legislator['patron_suffix'].'</a></li>';
	}
	$page_body .= '
			</ul>
		</div>';
}


$page_sidebar = <<<EOD
	
	<div class="box">
		<h3>Explanation</h3>
		<p>At left is the tally of who voted how on this bill.</p>
		
		<p>It’s important to understand that most bills are voted on multiple times, and the
		vote is not necessarily simply whether or not the bill should pass.  Be sure to look at
		the bill’s history to determine what, exactly, was being voted on, and at what point in
		the bill’s progress.</p>
	</div>
EOD;




# The status table.
$sql = 'SELECT DISTINCT bills_status.status, bills_status.translation,
		DATE_FORMAT(bills_status.date, "%m/%d/%Y") AS date, bills_status.date AS date_raw,
		bills_status.lis_vote_id, votes.total AS vote_count
		FROM bills_status
		LEFT JOIN votes
			ON bills_status.lis_vote_id = votes.lis_id
		WHERE bills_status.bill_id = '.$bill['id'].'
		ORDER BY date_raw DESC, bills_status.id DESC';
$result = @mysql_query($sql);
if (@mysql_num_rows($result) > 0)
{
	$bill['status_history'] = '';
	while ($status = @mysql_fetch_array($result))
	{
		
		# Provide a link to view this vote, but only if it's not the vote that we're currently
		# viewing.
		if (!empty($status['lis_vote_id']) && ($status['vote_count'] > 0) && ($status['lis_vote_id'] != $lis_id))
		{
			$tmp = '<a href="/bill/'.$bill['year'].'/'.strtolower($bill['number']).'/'.strtolower($status['lis_vote_id']).'/">'.$status['status'].'</a>';
			$status['status'] = $tmp;
		}
		$bill['status_history'] = '<li'.($status['lis_vote_id'] == $lis_id ? ' class="highlight"' : '').'>'.$status['date'].' '.$status['status'].'</li>'.$bill['status_history'];
	}
	$page_sidebar .= '
		
		<div class="box">
			<h3>Progress History</h3>
			'.$bill['status_history'].'
		</div>';
	
}

# OUTPUT THE PAGE

$page = new Page;
$page->page_title = $page_title;
$page->page_body = $page_body;
$page->page_sidebar = $page_sidebar;
$page->site_section = $site_section;
$page->html_head = $html_head;
$page->process();

?>
