<?php
defined('ABSPATH') || exit; // Exit if accessed directly
?>

<table id="cardcom-transactions" style="width: 100%;border:10px solid #456789;padding:4px;">
	<tbody>
		<th style="text-align: left;"><?php _e('Transaction Id', 'pmpro-cardcom'); ?></th>
		<th style="text-align: left;"><?php _e('Date', 'pmpro-cardcom'); ?></th>
		<th style="text-align: left;"><?php _e('Status', 'pmpro-cardcom'); ?></th>
		<th style="text-align: left;"><?php _e('Card last 4 dig', 'pmpro-cardcom'); ?></th>
		<th style="text-align: left;"><?php _e('Invoice', 'pmpro-cardcom'); ?></th>
		<?php foreach ($transactions as $transaction) : ?>
			<tr>
				<td>
					<?php
					echo $transaction->get_id();
					?>
				</td>
				<td>
					<?php
					echo $transaction->get_transactionDate();
					?>
				</td>
				<td>
					<?php
					echo $transaction->get_status();
					?>
				</td>
				<td>
					<?php
					echo $transaction->get_last4DigitsCardNumber();
					?>
				</td>
				<td>
					<?php
					$invoice = $transaction->get_invoiceLink();
					if (!empty($invoice) && $transaction->get_isDocumentCreated()) {
						printf(
							'<a href="https://secure.cardcom.solutions/api/PublicInvoice/Invoice?InvUniqId=%2$s" target="_blank">%1$s</a>',
							__('Get Invoice', 'pmpro-cardcom'),
							$invoice
						);
					}
					?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>