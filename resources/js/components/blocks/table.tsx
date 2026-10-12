import type { TableProps } from '@/types/blocks';
import { ButtonLink, SectionHeading } from './shared';

/**
 * Rows and columns of the Merchant's words: a ruled table on a wider screen,
 * and on a phone one card per row, each value under its column's name, so
 * nothing scrolls sideways.
 */
export function Table({ eyebrow, heading, text, columns, rows, footnote, button }: TableProps) {
    if (columns.length === 0 || rows.length === 0) {
        return null;
    }

    return (
        <div className="shell-container flex flex-col gap-6 py-5 md:gap-9 md:py-9">
            <SectionHeading eyebrow={eyebrow} heading={heading} text={text} />

            <div className="hidden overflow-hidden rounded-[18px] border border-border md:block">
                <table className="w-full border-collapse text-left">
                    <thead>
                        <tr className="bg-night text-white">
                            {columns.map((column, index) => (
                                <th key={index} scope="col" className="px-6 py-4 text-[15px] font-semibold">
                                    {column}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, rowIndex) => (
                            <tr key={rowIndex} className="border-t border-border even:bg-surface">
                                {row.cells.map((cell, cellIndex) =>
                                    cellIndex === 0 ? (
                                        <th key={cellIndex} scope="row" className="px-6 py-4 align-top text-[15px] font-semibold text-ink">
                                            {cell}
                                        </th>
                                    ) : (
                                        <td key={cellIndex} className="px-6 py-4 align-top text-[15px] leading-[1.7] text-body">
                                            {cell}
                                        </td>
                                    ),
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="flex flex-col gap-[10px] md:hidden">
                {rows.map((row, rowIndex) => (
                    <div key={rowIndex} className="flex flex-col gap-3 rounded-[14px] border border-border bg-white p-[18px]">
                        <b className="text-[17px] font-semibold text-ink">{row.cells[0]}</b>
                        {row.cells.slice(1).map((cell, cellIndex) =>
                            cell === '' ? null : (
                                <div key={cellIndex} className="flex flex-col gap-[2px]">
                                    <span className="text-[13px] font-semibold text-eyebrow">{columns[cellIndex + 1]}</span>
                                    <span className="text-[15px] leading-[1.7] text-body">{cell}</span>
                                </div>
                            ),
                        )}
                    </div>
                ))}
            </div>

            {(footnote || button) && (
                <div className="flex flex-wrap items-center justify-between gap-4">
                    {footnote && <p className="m-0 max-w-[720px] text-[14px] leading-[1.7] text-muted">{footnote}</p>}
                    {button && <ButtonLink button={button} className="rounded-[10px] bg-primary px-5 py-3 text-[15px] font-semibold text-white hover:bg-primary-dark hover:text-white" />}
                </div>
            )}
        </div>
    );
}
