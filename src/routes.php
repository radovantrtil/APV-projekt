<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

include 'location.php';

$app->get('/', function (Request $request, Response $response, $args) {
    // Render index view
    return $this->view->render($response, 'index.latte');
})->setName('index');



$app->post('/test', function (Request $request, Response $response, $args) {
    //read POST data
    $input = $request->getParsedBody();

    //log
    $this->logger->info('Your name: ' . $input['person']);

    return $response->withHeader('Location', $this->router->pathFor('index'));
})->setName('redir');


/* PERSONS*/


/* Zoznam vsech osob v DB */
$app->get('/persons', function (Request $request, Response $response, $args) {
	$params = $request->getQueryParams();
	if(empty($params['limit'])){
		$params['limit']=10;
	}
	if(empty($params['page'])){
		$params['page']=0;
	}
	$stmt= $this->db->query('SELECT count(*) pocet FROM person');
	$total_pages = $stmt->fetch()['pocet'];


	$stmt = $this->db->prepare('SELECT * FROM person ORDER BY first_name LIMIT :limit OFFSET :offset'); # toto vrati len DB objekt, nie vysledok!
	$stmt->bindValue(':limit', $params['limit']);
	$stmt->bindValue(':offset', $params['page']*$params['limit']);
	$stmt->execute();

	$tplVars= ['persons_list' => $stmt->fetchall(), # [ ['id_person' => 1, 'first_name' => 'Alice' ... ], ['id_person' => 2, 'first_name' => 'Bob' ... ] . ]
				'total_pages'=>$total_pages/$params['limit'],
				'page'=>$params['page'],
				'limit'=>$params['limit']
			];
	return $this->view->render($response, 'persons.latte', $tplVars);
}) ->setName('persons');


$app->get('/persons/search', function (Request $request, Response $response, $args) {
	$queryParams = $request->getQueryParams(); # [kluc => hodnota]
	if(! empty($queryParams) ) {
		$stmt = $this->db->prepare('SELECT * FROM person WHERE lower(first_name) = lower(:fname) OR lower(last_name) = lower(:lname)');
		$stmt->bindParam(':fname', $queryParams['q']);
		$stmt->bindParam(':lname', $queryParams['q']);
		$stmt->execute();
		$tplVars['persons_list'] = $stmt->fetchall();
		return $this->view->render($response, 'persons.latte', $tplVars);
	}
})->setName('persons_search');


/* nacitanie formularu */
$app->get('/person', function (Request $request, Response $response, $args) {
	$tplVars['formData'] = [
		'first_name' => '',
		'last_name' => '',
		'nickname' => '',
		'gender' => '',
		'height' => '',
		'birth_day' => '',
		'street_name' => '',
		'street_number' => '',
		'zip' => '',
		'city' => ''
	];
	return $this->view->render($response, 'newPerson.latte', $tplVars);
})->setName('newPerson');


/* spracovanie formu po odoslani */
$app->post('/person', function (Request $request, Response $response, $args) {
	$formData = $request->getParsedBody();
	$tplVars = [];
	if ( empty($formData['first_name']) || empty($formData['last_name']) || empty($formData['nickname']) ) {
		$tplVars['message'] = 'Please fill required fields!';
	} else {
		try {
			$this->db->beginTransaction();
			$id_location=null;
			if(!empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['zip']) || !empty($formData['city'])){

				$id_location = newLocation($this, $formData);
			}	

			$stmt = $this->db->prepare("INSERT INTO person (nickname, first_name, last_name, id_location, birth_day, height, gender) VALUES (:nickname, :first_name, :last_name, :id_location, :birth_day, :height, :gender)");	
				$stmt->bindValue(':nickname', $formData['nickname']);
				$stmt->bindValue(':first_name', $formData['first_name']);
				$stmt->bindValue(':last_name', $formData['last_name']);
				$stmt->bindValue(':id_location', $id_location ? $id_location : null);
				$stmt->bindValue(':gender', empty($formData['gender']) ? null : $formData['gender']);
				$stmt->bindValue(':birth_day', empty($formData['birth_day']) ? null : $formData['birth_day']);
				$stmt->bindValue(':height', empty($formData['height']) ? null : $formData['height']);
				$stmt->execute();
				$tplVars['message'] = 'Person successfully added!';
				$this->db->commit();

		} catch (PDOexception $e) {
			$tplVars['message'] = 'Something went wrong, adding person was not successful!';
			$this->logger->error($e->getMessage());
			$this->db->rollback();
		}
		$tplVars['formData'] = $formData;	
	}
	return $this->view->render($response, 'newPerson.latte', $tplVars);
});


/* nacitanie formularu */
$app->get('/person/update', function (Request $request, Response $response, $args) {
	$params = $request->getQueryParams(); # $params = [id_person => 1232, firstname => aaa]
	if (! empty($params['id_person'])) {
		$stmt = $this->db->prepare('SELECT * FROM person 
									LEFT JOIN location 
									USING(id_location) 
									WHERE id_person = :id_person');
		$stmt->bindValue(':id_person', $params['id_person']);
		$stmt->execute();
		$tplVars['formData'] = $stmt->fetch();

		if (empty($tplVars['formData'])) {
			exit('person not found');
		} else {
			return $this->view->render($response, 'updatePerson.latte', $tplVars);
		}
	}
})->setName('updatePerson');

/* odeslani formulare */
$app->post('/person/update', function (Request $request, Response $response, $args) {
	$id_person = $request->getQueryParam('id_person');
	$formData = $request->getParsedBody();
	$tplVars = [];
	if ( empty($formData['first_name']) || empty($formData['last_name']) || empty($formData['nickname']) ) {
		$tplVars['message'] = 'Please fill required fields';
	} else {
		try {
			#Kontrolujeme, zda je aspon jedna cast adreasy vyplnena
			if(!empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['zip']) || !empty($formData['city'])){

				$stmt = $this->db->prepare('SELECT id_location FROM person WHERE id_person = :id_person');
				$stmt->bindValue(':id_person', $id_person);
				$stmt->execute();
				$id_location = $stmt->fetch()['id_location']; #vrátí objekt {'id_location => 123'}
				

				if($id_location){
					# Osoba má adresu (id_location IS NOT NULL)
					editLocation($this, $id_location, $formData);
					$tplVars['message'] = 'Person successfully updated!';
				}else{
					# Osoba nemá adresu (id_location NULL)
					$id_location = newLocation($this, $formData);
					$tplVars['message'] = 'Person successfully updated!';
				}
			}

			$stmt = $this->db->prepare("UPDATE person SET 
												first_name = :first_name,  
												last_name = :last_name,
												nickname = :nickname,
												birth_day = :birth_day,
												gender = :gender,
												height = :height,
												id_location = :id_location
										WHERE id_person = :id_person");
			$stmt->bindValue(':nickname', $formData['nickname']);
			$stmt->bindValue(':first_name', $formData['first_name']);
			$stmt->bindValue(':last_name', $formData['last_name']);
			$stmt->bindValue(':id_location', $id_location ? $id_location : null);
			$stmt->bindValue(':gender', empty($formData['gender']) ? null : $formData['gender'] );
			$stmt->bindValue(':birth_day', empty($formData['birth_day']) ? null : $formData['birth_day']);
			$stmt->bindValue(':height', empty($formData['height']) ? null : $formData['height']);
			$stmt->bindValue(':id_person', $id_person);
			$stmt->execute();

		} catch (PDOexception $e) {
			$tplVars['message'] = 'Something went wrong, updating person was not successful!';
			$this->logger->error($e->getMessage());
		}       
	}
	$tplVars['formData'] = $formData;
	return $this->view->render($response, 'updatePerson.latte', $tplVars);
});

/* Info about osoby*/
$app->get('/person/info', function (Request $request, Response $response, $args){
	$id_person = $request->getQueryParam('id_person');
	$stmt=$this->db->prepare('SELECT * FROM person WHERE id_person = :id_person');
	$stmt->bindValue(':id_person',empty($id_person) ? null : $id_person);
	$stmt->execute();
	$tplVars['person'] = $stmt->fetch();

	$stmt = $this->db->prepare('SELECT * FROM location WHERE id_location= :id_location');
	$stmt->bindValue('id_location', $tplVars['person']['id_location']);
	$stmt->execute();
	$tplVars['location'] = $stmt->fetch();

	 $stmt = $this->db->prepare('SELECT * FROM contact LEFT JOIN contact_type USING (id_contact_type)
                                WHERE id_person = :id_person');
    $stmt->bindValue(':id_person', empty($id_person) ? null : $id_person);
    $stmt->execute();
    $tplVars['contact'] = $stmt->fetchAll();  

    $stmt = $this->db->prepare('SELECT * FROM person_meeting LEFT JOIN meeting USING (id_meeting) 
                                WHERE id_person = :id_person');
    $stmt->bindValue(':id_person', empty($id_person) ? null : $id_person);
    $stmt->execute();
    $tplVars['meeting'] = $stmt->fetchAll(); 

    $stmt = $this->db->prepare('SELECT first_name, last_name, name, id_person1, id_person2 
    							FROM person JOIN relation ON id_person = id_person2
        						JOIN relation_type USING (id_relation_type)
        						WHERE id_person1 = :id_person');
	$stmt->bindValue(':id_person', empty($id_person) ? null : $id_person);
	$stmt->execute();
	$tplVars['relations'] = $stmt->fetchAll();

	return $this->view->render($response, 'infoPerson.latte', $tplVars);
})->setName('person_info');

/* Delete osob */
$app->post('/persons/delete', function (Request $request, Response $response, $args){
	$id_person= $request->getQueryParam('id_person');
	if(!empty($id_person)){
		try{
			$stmt = $this->db->prepare('DELETE FROM person WHERE id_person = :id_person');
			$stmt->bindValue(':id_person',$id_person); #sql injection protect
			$stmt->execute();
		} catch (PDOexception $e) {
			$this->logger->error($e->getMessage());
			exit('error occured');
		}
	}else{
		exit('person is missing');
	}
	return  $response->withHeader('Location', $this->router->pathFor('persons'));
})->setName('person_delete');

	
/*CONTACT*/


/* page for adding contact */
$app->get('/person/contact', function (Request $request, Response $response, $args) {
	
	$tplVars['formData'] = [
		'contact' => '',
        'id_contact_type' => ''
	];
	return $this->view->render($response, 'contactForm.latte', $tplVars);
})->setName('person_contact');


/* Add contact */
$app->post('/person/contact', function (Request $request, Response $response, $args) {
	$formData = $request->getParsedBody();
	$params = $request->getQueryParams();
	$tplVars = [];
	 if (!empty($formData['contact'])) {
	 	try {
		 		$stmt = $this->db->prepare('INSERT INTO contact (id_person, id_contact_type, contact) VALUES (:id_person, :id_contact_type, :contact)');
		 		$stmt->bindValue(':id_person', $params['id_person']);
		 		$stmt->bindValue(':id_contact_type', $formData['id_contact_type']);
		 		$stmt->bindValue('contact', $formData['contact']);
				$stmt->execute();	
				$stmt = $this->db->prepare('SELECT * FROM contact JOIN contact_type USING (id_contact_type) WHERE id_person = :id_person ORDER BY id_contact');
				$stmt->bindValue(':id_person', $params['id_person']);
				$stmt->execute();				
            	$tplVars['contacts'] = $stmt->fetchAll();
			} catch (PDOexception $e) {
				$this->logger->error($e->getMessage());
			}
        }
    return $response->withHeader('Location', $this->router->pathFor('person_info') . '?id_person=' . $params['id_person']
    );
});

/* Delete contact */
$app->post('/person/contact/delete', function (Request $request, Response $response, $args) {
	$formData = $request->getParsedBody();
	try {
		$stmt = $this->db->prepare('DELETE FROM contact WHERE id_contact = :id_contact');
		$stmt->bindValue(':id_contact', $formData['id_contact']);
		$stmt->execute();
	} catch (PDOexception $e) {
		$this->logger->error($e->getMessage());
		exit('error occured');
	}
	return $response->withHeader('Location', $this->router->pathFor('person_info') . '?id_person=' . $formData['id_person']);
})->setName('contact_delete');


/*RELATIONS*/


/* vyhledávání vztahu podle osob */
$app->get('/relations/search', function (Request $request, Response $response, $args) {
	$queryParams = $request->getQueryParams(); # [kluc => hodnota]
	if(!empty($queryParams)){
		$stmt = $this->db->prepare('SELECT first_name1, last_name1, first_name2, last_name2, description, name FROM relation 
													LEFT JOIN (SELECT id_person as id_person1, first_name as first_name1, last_name as last_name1 FROM person) as person1 
																USING(id_person1)
													LEFT JOIN (SELECT id_person as id_person2, first_name as first_name2, last_name as last_name2 FROM person) as person2 
																USING(id_person2)
													LEFT JOIN relation_type USING (id_relation_type)
													WHERE lower(first_name1) = lower(:fname) OR lower(last_name1) = lower(:lname) OR lower(first_name2) = lower(:fname) OR lower(last_name2) = lower(:lname)');
		$stmt->bindParam(':fname', $queryParams['q']);
		$stmt->bindParam(':lname', $queryParams['q']);
		$stmt->execute();
		$tplVars['relations'] = $stmt->fetchall();

		return $this->view->render($response, 'relations.latte', $tplVars);
	}
})->setName('relations_search');


/* Seznam vztahů */
$app->get('/relations', function (Request $request, Response $response, $args) {
	$params = $request->getQueryParams();
	if(empty($params['limit'])){
		$params['limit']=10;
	}
	if(empty($params['page'])){
		$params['page']=0;
	}
	$stmt= $this->db->query('SELECT count(*) pocet FROM relation');
	$total_pages = $stmt->fetch()['pocet'];

	$stmt = $this->db->prepare('SELECT * FROM relation 
                                LEFT JOIN (SELECT id_person as id_person1, first_name as first_name1, last_name as last_name1 FROM person) as person1 USING(id_person1) 
                                LEFT JOIN (SELECT id_person as id_person2, first_name as first_name2, last_name as last_name2 FROM person) as person2 USING (id_person2)
                                LEFT JOIN relation_type USING(id_relation_type) ORDER BY id_relation DESC LIMIT :limit OFFSET :offset');

	$stmt->bindValue(':limit', $params['limit']);
	$stmt->bindValue(':offset', $params['page']*$params['limit']);
	$stmt->execute();

	$tplVars= ['relations' => $stmt->fetchall(), # [ ['id_person' => 1, 'first_name' => 'Alice' ... ], ['id_person' => 2, 'first_name' => 'Bob' ... ] . ]
				'total_pages'=>$total_pages/$params['limit'],
				'page'=>$params['page'],
				'limit'=>$params['limit']
			];

	return $this->view->render($response, 'relations.latte', $tplVars);
}) ->setName('relations');


/* nacteni formulare pro pridani relation*/
$app->get('/relation', function (Request $request, Response $response, $args) {
	 $params = $request->getQueryParams();
	$tplVars['formData'] = [
		 'id_person1' => '',
        'id_person2' => '',
        'description' => '',
        'id_relation_type' => '',
    ];
    $tplVars['persons1'] = $this->db->query('SELECT id_person, first_name, last_name FROM person ORDER BY first_name');
    $tplVars['persons2'] = $this->db->query('SELECT id_person, first_name, last_name FROM person ORDER BY first_name');
    $tplVars['relations'] = $this->db->query('SELECT * FROM relation_type ORDER BY id_relation_type');
	return $this->view->render($response, 'newRelation.latte', $tplVars);
})->setName('newRelation');


/* zpracovani odeslani formulare pro pridani relation */
$app->post('/relation', function (Request $request, Response $response, $args) {
	$formData = $request->getParsedBody();
	$tplVars = [];
	if($formData['id_person1'] == $formData['id_person2']){
		$tplVars['message'] = 'Can NOT add relation with yourself';
		$tplVars['formData'] = $formData;
	}else{
		if (empty($formData['id_person1']) || empty($formData['id_person2']) || empty($formData['id_relation_type'])) {
			$tplVars['message'] = 'Please fill required fields!';
			$tplVars['formData'] = $formData;
		} else {
			try {
				$this->db->beginTransaction();
				$stmt = $this->db->prepare('INSERT INTO relation (id_person1, id_person2, description, id_relation_type) VALUES (:id_person1, :id_person2, :description, :id_relation_type)');
				$stmt->bindValue(':id_person1', $formData['id_person1']);
				$stmt->bindValue(':id_person2', $formData['id_person2']);
				$stmt->bindValue(':description', $formData['description']);
				$stmt->bindValue(':id_relation_type', $formData['id_relation_type']);
				$stmt->execute();
            	$tplVars['message'] = 'Relation successfully added!';
            	$this->db->commit();
			} catch (PDOexception $e) {
				$tplVars['message'] = 'Something went wrong, adding relation was not successful!';
				$this->logger->error($e->getMessage());
				$this->db->rollback();
			}				
		}		
	}
	$tplVars['persons1'] = $this->db->query('SELECT id_person, first_name, last_name FROM person ORDER BY first_name');
    $tplVars['persons2'] = $this->db->query('SELECT id_person, first_name, last_name FROM person ORDER BY first_name');
    $tplVars['relations'] = $this->db->query('SELECT * FROM relation_type ORDER BY id_relation_type');
	$tplVars['formData'] = $formData;
	return $this->view->render($response, 'newRelation.latte', $tplVars);
});

/* nacitanie formularu */
$app->get('/relation/update', function (Request $request, Response $response, $args) {
	$params = $request->getQueryParams(); # $params = [id_person => 1232, firstname => aaa]
	if (! empty($params['id_relation'])) {
		$stmt = $this->db->prepare('SELECT * FROM relation 
                                    LEFT JOIN relation_type USING (id_relation_type)
                                    WHERE id_relation = :id_relation');
		$stmt->bindValue(':id_relation', $params['id_relation']);
		$stmt->execute();
		$tplVars['formData'] = $stmt->fetch();

		if (empty($tplVars['formData'])) {
			exit('relation not found');
		} else {
			$tplVars['persons1'] = $this->db->query('SELECT id_person, first_name, last_name 
                                            FROM person ORDER BY first_name');
    		$tplVars['persons2'] = $this->db->query('SELECT id_person, first_name, last_name 
                                            FROM person ORDER BY first_name');
    		$tplVars['relations'] = $this->db->query('SELECT * FROM relation_type ORDER BY id_relation_type');
			return $this->view->render($response, 'updateRelation.latte', $tplVars);
		}
	}
})->setName('updateRelation');

/* odeslani formulare */
$app->post('/relation/update', function (Request $request, Response $response, $args) {
	$id_relation = $request->getQueryParam('id_relation');
	$formData = $request->getParsedBody();
	$tplVars = [];
	if (empty($formData['id_person1']) || empty($formData['id_person2']) || empty($formData['id_relation_type'])) {
		$tplVars['message'] = 'Please fill required fields';
	} else {
		try {
			$stmt = $this->db->prepare('UPDATE relation SET id_person1 = :id_person1, id_person2 = :id_person2, description = :description, id_relation_type = :id_relation_type
                                            WHERE id_relation = :id_relation');
			$stmt->bindValue(":id_person1", $formData['id_person1']);
            $stmt->bindValue(':id_person2', $formData['id_person2']);
            $stmt->bindValue(':id_relation_type', $formData['id_relation_type']);
            $stmt->bindValue(':description', $formData['description']);
            $stmt->bindValue(':id_relation', $id_relation);
			$stmt->execute();
			$tplVars['message'] = 'Relation successfuly updated';
		} catch (PDOexception $e) {
			$tplVars['message'] = 'Something went wrong, updating relation was not successful!';
			$this->logger->error($e->getMessage());
		}       
	}
	 $tplVars['persons1'] = $this->db->query('SELECT id_person, first_name, last_name 
                                            FROM person ORDER BY first_name');
    $tplVars['persons2'] = $this->db->query('SELECT id_person, first_name, last_name 
                                            FROM person ORDER BY first_name');
    $tplVars['relations'] = $this->db->query('SELECT * FROM relation_type ORDER BY id_relation_type');
	$tplVars['formData'] = $formData;
	return $this->view->render($response, 'updateRelation.latte', $tplVars);
});

/* Delete relations */
$app->post('/relations/delete', function (Request $request, Response $response, $args){
	$id_relation= $request->getQueryParam('id_relation');
	if(!empty($id_relation)){
		try{
			$stmt = $this->db->prepare('DELETE FROM relation WHERE id_relation = :id_relation');
			$stmt->bindValue(':id_relation',$id_relation); #sql injection protect
			$stmt->execute();
		} catch (PDOexception $e) {
			$this->logger->error($e->getMessage());
			exit('error occured');
		}
	}else{
		exit('relation is missing');
	}
	return  $response->withHeader('Location', $this->router->pathFor('relations'));
})->setName('relation_delete');


/*MEETINGS*/


/* vyhledávání meetingu podle description */
$app->get('/meetings/search', function (Request $request, Response $response, $args) {
	$queryParams = $request->getQueryParams(); # [kluc => hodnota]
	if(!empty($queryParams)){
		$stmt = $this->db->prepare('SELECT *,(SELECT count(*) as participants_count FROM person_meeting WHERE person_meeting.id_meeting = meeting.id_meeting)
                                  FROM meeting WHERE lower(description) = lower(:description)');
		$stmt->bindParam(':description', $queryParams['q']);
		$stmt->execute();
		$tplVars['meetings'] = $stmt->fetchall();
		return $this->view->render($response, 'meetings.latte', $tplVars);
	}
})->setName('meetings_search');

/* meetingy */
$app->get('/meetings', function (Request $request, Response $response, $args) {
	$params = $request->getQueryParams();
	if(empty($params['limit'])){
		$params['limit']=10;
	}
	if(empty($params['page'])){
		$params['page']=0;
	}
	$stmt= $this->db->query('SELECT count(*) pocet FROM meeting');
	$total_pages = $stmt->fetch()['pocet'];

	$stmt = $this->db->prepare('SELECT *, 
                                    (SELECT count(*) as participants_count 
                                    FROM person_meeting WHERE person_meeting.id_meeting = meeting.id_meeting)
                                FROM meeting LEFT JOIN location USING (id_location) ORDER BY start LIMIT :limit OFFSET :offset');

	$stmt->bindValue(':limit', $params['limit']);
	$stmt->bindValue(':offset', $params['page']*$params['limit']);
	$stmt->execute();

	$tplVars= ['meetings' => $stmt->fetchall(), # [ ['id_person' => 1, 'first_name' => 'Alice' ... ], ['id_person' => 2, 'first_name' => 'Bob' ... ] . ]
				'total_pages'=>$total_pages/$params['limit'],
				'page'=>$params['page'],
				'limit'=>$params['limit']
			];
	
	return $this->view->render($response, 'meetings.latte', $tplVars);
}) ->setName('meetings');

/* nacteni formulare pro pridani meetingu*/
$app->get('/meeting', function (Request $request, Response $response, $args) {	
	$tplVars['formData'] = [
		 'start' => '',
        'duration' => '',
        'description' =>'',
        'city' => '',
        'street_name' => '',
        'street_number' => '',
        'zip' => '',
    ];
	return $this->view->render($response, 'newMeeting.latte', $tplVars);
})->setName('newMeeting');


/* zpracovani odeslani formulare pro pridani meetingu */
$app->post('/meeting', function (Request $request, Response $response, $args) {
	$formData = $request->getParsedBody();
	$tplVars = [];
	if (empty($formData['start'])) {
		$tplVars['message'] = 'Please fill required fields!';
	} else {
		try {
			$this->db->beginTransaction();	
			 $id_location = newLocation($this, $formData);
			 $stmt = $this->db->prepare('INSERT INTO meeting (start, description, duration, id_location) VALUES (:start, :description, :duration, :id_location)');
			 $stmt->bindValue(':start', $formData['start']);
             $stmt->bindValue(':description', $formData['description']);
             $stmt->bindValue(':duration', $formData['duration']);
             $stmt->bindValue(':id_location', $id_location);
             $stmt->execute();
			 $tplVars['message'] = 'Meeting successfully added!';
			 $this->db->commit();
		} catch (PDOexception $e) {
			$tplVars['message'] = 'Something went wrong, adding meeting was not successful!';
			$this->logger->error($e->getMessage());
			$this->db->rollback();
		}
		$tplVars['formData'] = $formData;	
	}
	return $this->view->render($response, 'newMeeting.latte', $tplVars);
});

/* nacitanie formularu */
$app->get('/meeting/update', function (Request $request, Response $response, $args) {
	$params = $request->getQueryParams(); # $params = [id_person => 1232, firstname => aaa]
	if (!empty($params['id_meeting'])) {
		$stmt = $this->db->prepare('SELECT * FROM meeting 
									LEFT JOIN location 
									USING(id_location) 
									WHERE id_meeting = :id_meeting');
		$stmt->bindValue(':id_meeting', $params['id_meeting']);
		$stmt->execute();
		$tplVars['formData'] = $stmt->fetch();

		$date=$tplVars['formData']['start'];
		$dbInsertDate = date('Y-m-d H:i', strtotime($date));
		$tplVars['formData']['start'] = $dbInsertDate;
		if (empty($tplVars['formData'])) {
			exit('meeting not found');
		} else {
			return $this->view->render($response, 'updateMeeting.latte', $tplVars);
		}
	}
})->setName('updateMeeting');

/* odeslani formulare */
$app->post('/meeting/update', function (Request $request, Response $response, $args) {
	$id_meeting = $request->getQueryParam('id_meeting');
	$formData = $request->getParsedBody();
	$tplVars = [];
	if (empty($formData['start'])) {
		$tplVars['message'] = 'Please fill required fields';
	} else {
		try {
			#Kontrolujeme, zda je aspon jedna cast adreasy vyplnena
			if(!empty($formData['street_name']) || !empty($formData['street_number']) || !empty($formData['zip']) || !empty($formData['city'])){

				$stmt = $this->db->prepare('SELECT id_location FROM meeting WHERE id_meeting = :id_meeting');
				$stmt->bindValue(':id_meeting', $id_meeting);
				$stmt->execute();
				$id_location = $stmt->fetch()['id_location']; #vrátí objekt {'id_location => 123'}				

				if($id_location){
					# Osoba má adresu (id_location IS NOT NULL)
					editLocation($this, $id_location, $formData);
					$tplVars['message'] = 'Meeting successfully updated!';
				}else{
					# Osoba nemá adresu (id_location NULL)
					$id_location = newLocation($this, $formData);
					$tplVars['message'] = 'Meeting successfully updated!';
				}
			}

			$stmt = $this->db->prepare('UPDATE meeting SET 
												start = :start, 
												description = :description,
												duration = :duration,
												id_location = :id_location
										WHERE id_meeting = :id_meeting');
			$stmt->bindValue(':start', $formData['start']);
            $stmt->bindValue(':description', $formData['description']);
            $stmt->bindValue(':duration', $formData['duration']);
            $stmt->bindValue(':id_location', $id_location);
            $stmt->bindValue(':id_meeting', empty($id_meeting) ? null : $id_meeting);
            $stmt->execute();
		} catch (PDOexception $e) {
			$tplVars['message'] = 'Something went wrong, updating meeting was not successful!';
			$this->logger->error($e->getMessage());
		}       
	}
	$tplVars['formData'] = $formData;
	return $this->view->render($response, 'updateMeeting.latte', $tplVars);
});

/* meeting info */
$app->get('/meeting/info', function (Request $request, Response $response, $args){
	$id_meeting = $request->getQueryParam('id_meeting');

	 $stmt = $this->db->prepare('SELECT * FROM meeting
                                LEFT JOIN location USING (id_location)
                                WHERE id_meeting = :id_meeting');
    $stmt->bindValue(':id_meeting', empty($id_meeting) ? null : $id_meeting);
    $stmt->execute();
    $tplVars['meeting'] = $stmt->fetch();

    $stmt = $this->db->prepare('SELECT * FROM person_meeting
                                LEFT JOIN person USING (id_person)
                                WHERE id_meeting = :id_meeting ORDER BY first_name');
    $stmt->bindValue(':id_meeting', empty($id_meeting) ? null : $id_meeting);
    $stmt->execute();
    $tplVars['participant'] = $stmt->fetchAll();

    $stmt = $this->db->prepare('SELECT id_person, first_name, last_name 
                                FROM person WHERE id_person NOT IN
                                    (SELECT id_person FROM person_meeting
                                LEFT JOIN person USING (id_person)
                                WHERE id_meeting = :id_meeting)
                                ORDER BY first_name');
    $stmt->bindValue(':id_meeting', empty($id_meeting) ? null : $id_meeting);
    $stmt->execute();
    $tplVars['person'] = $stmt->fetchAll();

	return $this->view->render($response, 'infoMeeting.latte', $tplVars);
})->setName('meeting_info');

/* adding new participant */
$app->post('/meetings/participant/add', function (Request $request, Response $response, $args) {
    $id_meeting = $request->getQueryParam('id_meeting');
    $formData = $request->getParsedBody();
    $id_person = $formData['id_person'];

    if(!empty($id_person) && !empty($id_meeting)){
    	try {
	    	$stmt = $this->db->prepare('INSERT INTO person_meeting (id_person, id_meeting) VALUES (:id_person, :id_meeting)');
	        $stmt->bindValue(':id_person', $id_person);
            $stmt->bindValue(':id_meeting', $id_meeting);
            $stmt->execute();	  
	    } catch (PDOException $e) {
	    	$this->logger->error($e->getMessage());	        
	    }
	}else{
		exit('participant is missing');
	}  
	$tplVars['id_meeting'] = $id_meeting;

    return $response->withHeader('Location',$this->router->pathFor('meeting_info', [], $tplVars));
})->setName('participant_add');


/* delete participant */
$app->post('/meeting/participant/delete', function (Request $request, Response $response, $args) {
    $formData = $request->getParsedBody();
    $id_meeting = $formData['id_meeting'];
    $id_person = $formData['id_person'];
    if(!empty($id_person) && !empty($id_meeting)){
	    try {
	    	$stmt = $this->db->prepare('DELETE FROM person_meeting WHERE id_meeting = :id_meeting AND id_person = :id_person');
		    $stmt->bindValue(':id_person', $id_person);
		    $stmt->bindValue(':id_meeting', $id_meeting);
		    $stmt->execute();
		} catch (PDOException $e) {		
		    $this->logger->error($e->getMessage());	        
		}
	}else{
		exit('participant is missing');
	}  	  
	 $tplVars['id_meeting'] = $id_meeting;
  
    return $response->withHeader('Location', $this->router->pathFor('meeting_info', [], $tplVars));

})->setName('participant_delete');

/* Delete meeting */
$app->post('/meetings/delete', function (Request $request, Response $response, $args){
	$id_meeting= $request->getQueryParam('id_meeting');
	if(!empty($id_meeting)){
		try{
			$stmt = $this->db->prepare('DELETE FROM meeting WHERE id_meeting = :id_meeting');
			$stmt->bindValue(':id_meeting', $id_meeting); #sql injection protect
			$stmt->execute();
		} catch (PDOexception $e) {
			$this->logger->error($e->getMessage());
			exit('error occured');
		}
	}else{
		exit('meeting is missing');
	}
	return  $response->withHeader('Location', $this->router->pathFor('meetings'));
})->setName('meeting_delete');