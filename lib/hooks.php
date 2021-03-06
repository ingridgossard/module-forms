<?php

/*
 * This file is part of the Icybee package.
 *
 * (c) Olivier Laviale <olivier.laviale@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Icybee\Modules\Forms;

use ICanBoogie\Operation;
use ICanBoogie\Operation\ProcessEvent;

class Hooks
{
	static public function markup_form(array $args, \Patron\Engine $patron, $template)
	{
		$id = $args['select'];
		$model = \ICanBoogie\app()->models['forms'];

		if (is_numeric($id))
		{
			$form = $model[$id];
		}
		else
		{
			$form = $model->own->visible->filter_by_slug($id)->one;
		}

		if (!$form)
		{
			throw new \Exception(\ICanBoogie\format('Unable to retrieve form using supplied conditions: %conditions', [ '%conditions' => json_encode($args['select']) ]));
		}

		new \BlueTihi\Context\LoadedNodesEvent($patron->context, [ $form ]);

		if (!$form->is_online)
		{
			throw new \Exception(\ICanBoogie\format('The form %title is offline', [ '%title' => $form->title ]));
		}

		return (string) $form;
	}

	/**
	 * Tries to load the form associated with the operation.
	 *
	 * This function is a callback for the `ICanBoogie\Operation::get_form` event.
	 *
	 * The {@link OPERATION_POST_ID} parameter provides the key of the form active record to load.
	 *
	 * If the form is successfully retrieved a callback is added to the
	 * "<operation_class>::process" event, it is used to send a notify message with the parameters
	 * provided by the form active record. The callback also provides further processing.
	 *
	 * At the very end of the process, the `Icybee\Modules\Forms\Form::sent` event is fired.
	 *
	 * Notifying
	 * =========
	 *
	 * If defined, the `alter_notify` method of the form is invoked to alter the notify options.
	 * The method is wrapped with the `Icybee\Modules\Forms\Form::alter_notify:before` and
	 * `Icybee\Modules\Forms\Form::alter_notify` events.
	 *
	 * If the `is_notify` property of the record is true a notify message is sent with the notify
	 * options.
	 *
	 * Result tracking
	 * ===============
	 *
	 * The result of the operation using the form is stored in the session under
	 * `[modules][forms][rc][<record_nid>]`. This stored value is used when the form is
	 * rendered to choose what to render. For example, if the value is empty, the form is rendered
	 * with the `before` and `after` messages, otherwise only the `complete` message is rendered.
	 *
	 * @param \ICanBoogie\Operation\GetFormEvent $event
	 * @param Operation $operation
	 */
	static public function on_operation_get_form(Operation\GetFormEvent $event, Operation $operation)
	{
		$app = \ICanBoogie\app();
		$request = $event->request;

		if (!$request[Module::OPERATION_POST_ID])
		{
			return;
		}

		$record = $app->models['forms'][(int) $request[Module::OPERATION_POST_ID]];
		$form = $record->form;

		$event->form = $form;
		$event->stop();

		$app->events->attach(get_class($operation) . '::process', function(Operation\ProcessEvent $event, Operation $operation) use ($app, $record, $form) {

			$rc = $event->rc;
			$bind = $event->request->params;
			$template = $record->notify_template;
			$mailer = null;
			$mailer_tags = [

				'bcc' => $record->notify_bcc,
				'to' => $record->notify_destination,
				'from' => $record->notify_from,
				'subject' => $record->notify_subject,
				'body' => null

			];

			$notify_params = new NotifyParams([

				'record' => $record,
				'event' => $event,
				'operation' => $operation,

				'rc' => &$rc,
				'bind' => &$bind,
				'template' => &$template,
				'mailer' => &$mailer,
				'mailer_tags' => &$mailer_tags

			]);

			new Form\BeforeAlterNotifyEvent($record, [

				'params' => $notify_params,
				'event' => $event,
				'operation' => $operation

			]);

			if ($form instanceof AlterFormNotifyParams)
			{
				$form->alter_form_notify_params($notify_params);
			}

			new Form\AlterNotifyEvent($record, [

				'params' => $notify_params,
				'event' => $event,
				'operation' => $operation

			]);

			#
			# The result of the operation is stored in the session and is used in the next
			# session to present the `success` message instead of the form.
			#
			# Note: The result is not stored for XHR.
			#

			if (!$event->request->is_xhr)
			{
				$app->session->modules['forms']['rc'][$record->nid] = $rc;
			}

			$message = null;

			if ($record->is_notify)
			{
				$patron = new \Patron\Engine();

				if (!$mailer_tags['body'])
				{
					$mailer_tags['body'] = $template;
				}

				foreach ($mailer_tags as &$value)
				{
					$value = $patron($value, $bind);
				}

				$message = $mailer_tags['body'];

				new Form\AlterMailerTagsEvent($record, $mailer_tags);

				if ($mailer)
				{
					$mailer($mailer_tags);
				}
				else
				{
					$app->mail($mailer_tags);
				}
			}

			new Form\NotifyEvent($record, [

				'params' => $notify_params,
				'message' => &$message,
				'event' => $event,
				'request' => $event->request,
				'operation' => $operation

			]);
		});
	}
}

class NotifyParams
{
	/**
	 * Reference to the result of the operation.
	 *
	 * @var mixed
	 */
	public $rc;

	/**
	 * Reference to the `this` value used to render the template.
	 *
	 * @var mixed
	 */
	public $bind;

	/**
	 * Reference to the template used to render the message.
	 *
	 * @var string
	 */
	public $template;

	/**
	 * Reference to the mailer instance.
	 *
	 * Use this property to provide your own mailer.
	 *
	 * @var mixed
	 */
	public $mailer;

	/**
	 * Reference to the tags used to create the mailer object.
	 *
	 * @var array
	 */
	public $mailer_tags;

	/**
	 * The {@link Form} instance.
	 *
	 * @var Form
	 */
	public $record;

	/**
	 * The event that triggered the notification.
	 *
	 * @var \ICanBoogie\Operation\ProcessEvent
	 */
	public $event;

	/**
	 * The operation that triggered the event.
	 *
	 * @param \ICanBoogie\Operation
	 */
	public $operation;

	public function __construct(array $params)
	{
		foreach ($params as $k => &$v)
		{
			$this->$k = &$v;
		}
	}
}

namespace Icybee\Modules\Forms\Form;

/**
 * Event class for the `Icybee\Modules\Forms\Form::alter_notify:before` event.
 */
class BeforeAlterNotifyEvent extends \ICanBoogie\Event
{
	/**
	 * Notify parameters.
	 *
	 * @var \Icybee\Modules\Forms\NotifyParams
	 */
	public $params;

	/**
	 * The event that triggered the notification.
	 *
	 * @var \ICanBoogie\Operation\ProcessEvent
	 */
	public $event;

	/**
	 * The operation that triggered the {@link ProcessEvent} event.
	 *
	 * @var \ICanBoogie\Operation
	 */
	public $operation;

	/**
	 * The event is constructed with the type `alter_notify:before`.
	 *
	 * @param \Icybee\Modules\Forms\Form $target
	 * @param array $payload
	 */
	public function __construct(\Icybee\Modules\Forms\Form $target, array $payload)
	{
		parent::__construct($target, 'alter_notify:before', $payload);
	}
}

/**
 * Event class for the `Icybee\Modules\Forms\Form::alter_notify` event.
 */
class AlterNotifyEvent extends \ICanBoogie\Event
{
	/**
	 * Notify parameters.
	 *
	 * @var \Icybee\Modules\Forms\NotifyParams
	 */
	public $params;

	/**
	 * The event that triggered the notification.
	 *
	 * @var \ICanBoogie\Operation\ProcessEvent
	 */
	public $event;

	/**
	 * The operation that triggered the {@link ProcessEvent} event.
	 *
	 * @var \ICanBoogie\Operation
	 */
	public $operation;

	/**
	 * The event is constructed with the type `alter_notify`.
	 *
	 * @param \Icybee\Modules\Forms\Form $target
	 * @param array $payload
	 */
	public function __construct(\Icybee\Modules\Forms\Form $target, array $payload)
	{
		parent::__construct($target, 'alter_notify', $payload);
	}
}

/**
 * Event class for the `Icybee\Modules\Forms\Form::notify` event.
 */
class NotifyEvent extends \ICanBoogie\Event
{
	/**
	 * Notify parameters.
	 *
	 * @var \Icybee\Modules\Forms\NotifyParams
	 */
	public $params;

	/**
	 * Reference to the message sent.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * The operation `process` event.
	 *
	 * @var \ICanBoogie\OperationProcessEvent
	 */
	public $event;

	/**
	 * The request that triggered the operation.
	 *
	 * @var \ICanBoogie\HTTP\Request
	 */
	public $request;

	/**
	 * The operation that submitted the form.
	 *
	 * @var \ICanBoogie\Operation
	 */
	public $operation;

	/**
	 * The event is constructed with the type `notify`.
	 *
	 * @param \Icybee\Modules\Forms\Form $target
	 * @param array $payload
	 */
	public function __construct(\Icybee\Modules\Forms\Form $target, array $payload)
	{
		parent::__construct($target, 'notify', $payload);
	}
}

class AlterMailerTagsEvent extends \ICanBoogie\Event
{
	public $mailer_tags;

	public function __construct(\Icybee\Modules\Forms\Form $target, array &$mailer_tags)
	{
		$this->mailer_tags = &$mailer_tags;

		parent::__construct($target, 'alter_mailer_tags');
	}
}
