import { Form, router } from '@inertiajs/react';
import BaselineDocumentController from '@/actions/App/Http/Controllers/BaselineDocumentController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BaselineView } from '@/types';

type Props = {
    baseline: BaselineView;
    onContinue: () => void;
};

export function formatBytes(bytes: number) {
    if (bytes >= 1_000_000) {
        return `${(bytes / 1_000_000).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1000))} KB`;
}

export default function BaselineStepContract({ baseline, onContinue }: Props) {
    const removeDocument = (documentId: string) =>
        router.delete(
            BaselineDocumentController.destroy.url({
                baseline: baseline.id,
                document: documentId,
            }),
            { preserveScroll: true, preserveState: true },
        );

    return (
        <div className="border-[1.5px] border-ink dark:border-paper">
            <div className="border-b-[1.5px] border-ink px-4 py-3 dark:border-paper">
                <span className="font-plex-mono text-[11px] font-semibold tracking-[0.08em] text-stone uppercase dark:text-fog">
                    Step 2 · Contract
                </span>
            </div>
            <div className="flex flex-col gap-5 px-4 py-5">
                <p className="max-w-xl text-[13px] text-stone dark:text-fog">
                    Upload the signed SOW, proposal and annexes. Documents stay
                    private to your organization until the baseline is approved;
                    structure items will trace to their clauses.
                </p>

                {baseline.documents.length > 0 && (
                    <ul className="flex flex-col divide-y divide-ink/20 border-[1.5px] border-ink dark:divide-paper/20 dark:border-paper">
                        {baseline.documents.map((document) => (
                            <li
                                key={document.id}
                                className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                            >
                                <a
                                    href={BaselineDocumentController.show.url({
                                        baseline: baseline.id,
                                        document: document.id,
                                    })}
                                    className="font-medium hover:text-rust"
                                >
                                    {document.filename}
                                </a>
                                <span className="flex items-center gap-3 font-plex-mono text-[11px] text-stone dark:text-fog">
                                    {formatBytes(document.sizeBytes)}
                                    {document.uploadedAt !== null &&
                                        ` · ${document.uploadedAt}`}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="rounded-none font-plex-mono text-[11px] font-semibold text-rust uppercase hover:text-rust"
                                        onClick={() =>
                                            removeDocument(document.id)
                                        }
                                    >
                                        Remove
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                <Form
                    {...BaselineDocumentController.store.form(baseline.id)}
                    options={{ preserveScroll: true, preserveState: true }}
                    resetOnSuccess
                    className="flex flex-col gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="baseline-document">
                                    Add a document
                                </Label>
                                <Input
                                    id="baseline-document"
                                    name="document"
                                    type="file"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.md"
                                    required
                                    className="rounded-none border-[1.5px] border-ink shadow-none dark:border-paper"
                                />
                                <InputError message={errors.document} />
                            </div>
                            <div className="flex justify-between gap-2">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    variant="secondary"
                                    className="rounded-none shadow-none"
                                    data-test="baseline-upload-document"
                                >
                                    Upload
                                </Button>
                                <Button
                                    type="button"
                                    onClick={onContinue}
                                    className="rounded-none bg-ink font-semibold text-paper shadow-none hover:bg-rust dark:bg-paper dark:text-ink dark:hover:bg-rust dark:hover:text-paper"
                                    data-test="baseline-contract-continue"
                                >
                                    Continue →
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </div>
    );
}
