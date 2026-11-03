import { __ } from '@wordpress/i18n';
import { Section } from './Section';
import type { Progress, StatusResponse } from './types';

interface Props {
	isSupported: StatusResponse[ 'is_supported' ];
	installed: StatusResponse[ 'installed' ];
	indexed: StatusResponse[ 'indexed' ];
	tableDims: StatusResponse[ 'table_dims' ];
	progress: Progress | null;
}

export function StatusSection( {
	isSupported,
	installed,
	indexed,
	tableDims,
	progress,
}: Props ) {
	return (
		<Section title={ __( 'Status', 'squirrel-search' ) }>
			<table className="widefat striped">
				<tbody>
					<tr>
						<td>
							{ __(
								'MariaDB VECTOR support',
								'squirrel-search'
							) }
						</td>
						<td>
							{ isSupported ? (
								<span style={ { color: 'green' } }>
									{ '✓ ' }
									{ __( 'Available', 'squirrel-search' ) }
								</span>
							) : (
								<span style={ { color: 'red' } }>
									{ '✗ ' }
									{ __(
										'Not available (MariaDB 11.7+ required)',
										'squirrel-search'
									) }
								</span>
							) }
						</td>
					</tr>
					<tr>
						<td>{ __( 'Indexed posts', 'squirrel-search' ) }</td>
						<td>{ installed ? indexed : '—' }</td>
					</tr>
					<tr>
						<td>{ __( 'Table dimensions', 'squirrel-search' ) }</td>
						<td>{ tableDims ?? '—' }</td>
					</tr>
					<tr>
						<td>{ __( 'Backfill status', 'squirrel-search' ) }</td>
						<td>
							{ progress
								? `${ progress.done } / ${ progress.total } posts`
								: __( 'Idle', 'squirrel-search' ) }
						</td>
					</tr>
				</tbody>
			</table>
		</Section>
	);
}
