<?php

require_once 'vendor/autoload.php';
use \models\Crontab;
use \models\SystemUser;
use \models\At;
use \forms\AddJob;
use \services\ExpressionService;

$app = new \Slim\Slim(array(
	'templates.path' => 'application/views',
	'debug' => true
));
\Slim\Route::setDefaultConditions(array(
    'hash' => '[a-z0-9]{8}'
));

// Initialize layout and store it, and use it right away
// as the view for non-XHR requests
$view = new \library\App\Layout();
$view->setTemplatesDirectory($app->config('templates.path'));
$app->config('view', $view);
if (!$app->request->isXhr()) {
	$app->view($view);
}

// Routes
$app->get('/', function() use ($app) {
	$crontab	  = new Crontab();
	$systemUser   = new SystemUser();
	$simpleForm   = new AddJob\SimpleForm();
	$advancedForm = new AddJob\AdvancedForm();
	
	$showAlertAtUnavailable = $app->getCookie('showAlertAtUnavailable');
	$app->view->setData('showAtUnavailableAlert', $showAlertAtUnavailable !== null ?
			(bool) $showAlertAtUnavailable : true);
	
	$app->render('index.phtml', array(
		'crontab'              => $crontab,
		'systemUser'           => $systemUser,
		'isAtCommandAvailable' => At::isAvailable(),
		'atCommandErrorOutput' => At::getErrorOutput(),
		'simpleForm'           => $simpleForm,
		'advancedForm'         => $advancedForm
	));
});

/**
 * Groups cron job related routes.
 */
$app->group('/job', function() use ($app) {
	/**
	 * Should be used as a route middleware to allow for the response
	 * to be JSON in the route's callable.
	 * 
	 * @return void
	 */
	$setupJsonResponse = function() {
		$app = \Slim\Slim::getInstance();
		
        $app->view(new \JsonApiView());
        $app->add(new \JsonApiMiddleware());
	};
	
	/**
	 * Adds or edits a cron job.
	 */
	$app->post('/save', $setupJsonResponse, function() use ($app) {
		$formData = $app->request->post();
		$hash = $app->request->params('hash');
		
		$form = AddJob\FormFactory::createForm($formData);
		if ($form->isValid()) {
			$crontab = new Crontab();
			
			if ($formData['mode'] == AddJob\FormFactory::SIMPLE) {
				$expression = ExpressionService::createExpression($formData);
			} else {
				$expression = $formData['expression'];
			}
			
			// This is an edit
			if ($hash) {	
				$job = $crontab->findByHash($hash);
				if (!$job) {
					$app->render(500, array(
						'error' => true,
						'msg' => 'Cron job no longer exists'
					));
				}
			} else {
				$job = new Crontab\Job();
			}
			
			$job->setExpression($expression);
			$job->setCommand($formData['command']);
			$job->setComment($formData['name']);
			
			if ($hash) {
				$crontab->update($job);
			} else {
				$crontab->add($job);
			}
			$crontab->save();
			
			$response = array(
				'error' => false,
				'msg' => 'The job has been saved.',
				'hash' => $job->getHash()
			);
			if ((bool) $formData['returnHtml']) {
				$response['html'] = $app->config('view')->partial('partials/job.phtml', array(
					'job' => $job
				));
			}
			
			$app->render(200, $response);
		} else {
			$app->render(500, array(
				'error' => true,
				'msg' => $form->getFormattedMessages()
			));
		}
	});
	
	$app->get('/edit-form/:hash', $setupJsonResponse, function($hash) use ($app) {
		$crontab = new Crontab();
		$job = $crontab->findByHash($hash);
		if (!$job) {
			$app->render(404, array(
				'error' => true,
				'msg' => 'Cron job no longer exists'
			));
		}
		
		$expressionService = new ExpressionService();
		
		// Prepare simple form
		$simpleForm = null;
		if ($expressionService->isSimpleExpression($job->getExpression())) {
			$simpleForm = new AddJob\SimpleForm();
			$simpleForm->get('name')->setValue($job->getComment());
			$simpleForm->get('command')->setValue($job->getCommand());
			$expressionService->hydrateSimpleForm($job->getExpression(), $simpleForm);
		}
		
		// Prepare advanced form
		$advancedForm = new AddJob\AdvancedForm();
		$advancedForm->get('name')->setValue($job->getComment());
		$advancedForm->get('command')->setValue($job->getCommand());
		$advancedForm->get('expression')->setValue($job->getExpression());
		
		$app->render(200, array(
			'error' => false,
			'html'  => $app->config('view')->partial('partials/job-edit-dialog.phtml', array(
				'hash'		   => $hash,
				'simpleForm'   => $simpleForm,
				'advancedForm' => $advancedForm
			))
		));
	});
	
	/**
	 * Runs a cron job in background.
	 */
	$app->get('/run/:hash', $setupJsonResponse, function($hash) use ($app) {
		$crontab = new Crontab();
		$job = $crontab->findByHash($hash);
		
		if ($job) {
			$crontab->run($job);
			
			$app->render(200, array(
				'error' => false,
				'msg' => 'Process started.'
			));
		} else {
			$app->render(404, array(
				'error' => true,
				'msg' => 'Cron job no longer exists'
			));
		}
	});
	
	/**
	 * Pauses schedule by commenting the job in crontab, so it no longer runs when
	 * is is supposed to.
	 */
	$app->get('/pause/:hash', $setupJsonResponse, function($hash) use ($app) {
		$crontab = new Crontab();
		$job = $crontab->findByHash($hash);
		
		if ($job) {
			$crontab->pause($job)->save();
			
			$app->render(200, array(
				'error' => false,
				'msg' => 'Job schedule has been paused.',
				'hash' => $job->getHash()
			));
		} else {
			$app->render(404, array(
				'error' => true,
				'msg' => 'Cron job no longer exists'
			));
		}
	});
	
	/**
	 * Resumes schedule by un-commenting the job in crontab.
	 */
	$app->get('/resume/:hash', $setupJsonResponse, function($hash) use ($app) {
		$crontab = new Crontab();
		$job = $crontab->findByHash($hash);
		
		if ($job) {
			$crontab->resume($job)->save();
			
			$app->render(200, array(
				'error' => false,
				'msg' => 'Job schedule has been resumed.',
				'hash' => $job->getHash()
			));
		} else {
			$app->render(404, array(
				'error' => true,
				'msg' => 'Cron job no longer exists.'
			));
		}
	});
	
	/**
	 * Deletes job from crontab.
	 */
	$app->get('/delete/:hash', $setupJsonResponse, function($hash) use ($app) {
		$crontab = new Crontab();
		$job = $crontab->findByHash($hash);
		
		if ($job) {
			$crontab->delete($job)->save();
			
			$app->render(200, array(
				'error' => false,
				'msg' => 'Job has been deleted.'
			));
		} else {
			$app->render(404, array(
				'error' => true,
				'msg' => 'Cron job no longer exists.'
			));
		}
	});
});

$app->run();