import { EmptyState } from './AsyncState';

export default function DataTable({ columns, rows, rowKey = 'id', emptyTitle, emptyDescription }) {
    if (!rows?.length) return <EmptyState title={emptyTitle} description={emptyDescription} />;

    return (
        <div className="ui-table-wrap">
            <table className="ui-table">
                <thead><tr>{columns.map(column => <th key={column.key}>{column.label}</th>)}</tr></thead>
                <tbody>{rows.map((row, index) => (
                    <tr key={typeof rowKey === 'function' ? rowKey(row) : row[rowKey] ?? index}>
                        {columns.map(column => <td key={column.key} data-label={column.label}>{column.render ? column.render(row) : row[column.key]}</td>)}
                    </tr>
                ))}</tbody>
            </table>
        </div>
    );
}
