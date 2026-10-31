import { Form, useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import EvidenceViewer from '@/components/research/evidence-viewer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import type { ResearchQuestion, ValidationReport } from '@/types/research';

const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-70 dark:bg-input/30';

export default function QuestionEditor({
    question,
    position,
    base,
    version,
    frozen,
    errors,
}: {
    question: ResearchQuestion;
    position: number;
    base: string;
    version: number;
    frozen: boolean;
    errors: ValidationReport['errors'];
}) {
    const { t } = useTranslations();
    const [verifyOpen, setVerifyOpen] = useState(false);
    const form = useForm({
        lock_version: version,
        question_id: question.question_id,
        category: question.category,
        question: question.question,
        reference_answer: question.reference_answer,
        required_facts: question.required_facts,
        evidence: question.evidence,
    });
    const endpoint = base + '/questions/' + position;
    const prefix = 'question-' + position + '-';

    return (
        <Card id={'question-' + position}>
            <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-3">
                    <span>
                        {position + 1}.{' '}
                        {question.question_id || t('research.question')}
                    </span>
                    <Badge
                        variant={
                            question.verification.verified
                                ? 'default'
                                : 'secondary'
                        }
                    >
                        {t(
                            question.verification.verified
                                ? 'research.verified'
                                : 'research.unverified',
                        )}
                    </Badge>
                </CardTitle>
                {question.verification.verified && (
                    <p className="text-xs text-muted-foreground">
                        {question.verification.verified_by} ·{' '}
                        {question.verification.verified_at}
                    </p>
                )}
            </CardHeader>
            <CardContent className="space-y-4">
                {errors.map((error, i) => (
                    <InputError key={i} message={error.message} />
                ))}
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            lock_version: version,
                        }));
                        form.patch(endpoint, { preserveScroll: true });
                    }}
                >
                    <fieldset
                        disabled={frozen || form.processing}
                        className="space-y-4"
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(['question_id', 'category'] as const).map(
                                (field) => (
                                    <div key={field} className="space-y-2">
                                        <Label htmlFor={prefix + field}>
                                            {t('research.' + field)}
                                        </Label>
                                        <Input
                                            id={prefix + field}
                                            value={form.data[field]}
                                            onChange={(event) =>
                                                form.setData(
                                                    field,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors[field]}
                                        />
                                    </div>
                                ),
                            )}
                        </div>
                        {(['question', 'reference_answer'] as const).map(
                            (field) => (
                                <div key={field} className="space-y-2">
                                    <Label htmlFor={prefix + field}>
                                        {t('research.' + field)}
                                    </Label>
                                    <textarea
                                        id={prefix + field}
                                        rows={field === 'question' ? 3 : 5}
                                        className={textareaClass}
                                        value={form.data[field]}
                                        onChange={(event) =>
                                            form.setData(
                                                field,
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={form.errors[field]} />
                                </div>
                            ),
                        )}
                        <div className="space-y-2">
                            <Label htmlFor={prefix + 'facts'}>
                                {t('research.required_facts')}
                            </Label>
                            <textarea
                                id={prefix + 'facts'}
                                rows={4}
                                className={textareaClass}
                                value={form.data.required_facts.join('\n')}
                                onChange={(event) =>
                                    form.setData(
                                        'required_facts',
                                        event.target.value === ''
                                            ? []
                                            : event.target.value.split('\n'),
                                    )
                                }
                            />
                            <InputError message={form.errors.required_facts} />
                        </div>
                    </fieldset>
                    <h3 className="font-medium">{t('research.evidence')}</h3>
                    {form.data.evidence.map((item, i) => (
                        <div
                            key={i}
                            className="space-y-3 rounded-md border p-3"
                        >
                            <fieldset
                                disabled={frozen || form.processing}
                                className="grid gap-3 sm:grid-cols-[1fr_7rem_7rem_auto]"
                            >
                                {(
                                    ['path', 'start_line', 'end_line'] as const
                                ).map((field) => (
                                    <div key={field} className="space-y-2">
                                        <Label htmlFor={prefix + i + field}>
                                            {t('research.' + field)}
                                        </Label>
                                        <Input
                                            id={prefix + i + field}
                                            type={
                                                field === 'path'
                                                    ? 'text'
                                                    : 'number'
                                            }
                                            min={
                                                field === 'path' ? undefined : 1
                                            }
                                            value={item[field]}
                                            onChange={(event) =>
                                                form.setData(
                                                    'evidence',
                                                    form.data.evidence.map(
                                                        (evidence, index) =>
                                                            index === i
                                                                ? {
                                                                      ...evidence,
                                                                      [field]:
                                                                          field ===
                                                                          'path'
                                                                              ? event
                                                                                    .target
                                                                                    .value
                                                                              : Number(
                                                                                    event
                                                                                        .target
                                                                                        .value,
                                                                                ),
                                                                  }
                                                                : evidence,
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                ))}
                                {!frozen && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="self-end"
                                        onClick={() =>
                                            form.setData(
                                                'evidence',
                                                form.data.evidence.filter(
                                                    (_, index) => index !== i,
                                                ),
                                            )
                                        }
                                    >
                                        {t('research.remove')}
                                    </Button>
                                )}
                            </fieldset>
                            <EvidenceViewer
                                url={endpoint + '/evidence/' + i}
                                disabled={form.isDirty}
                            />
                        </div>
                    ))}
                    {!frozen && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() =>
                                form.setData('evidence', [
                                    ...form.data.evidence,
                                    { path: '', start_line: 1, end_line: 1 },
                                ])
                            }
                        >
                            {t('research.add_evidence')}
                        </Button>
                    )}
                    {Object.entries(form.errors)
                        .filter(
                            ([key]) =>
                                ![
                                    'question_id',
                                    'category',
                                    'question',
                                    'reference_answer',
                                    'required_facts',
                                ].includes(key),
                        )
                        .map(([key, message]) => (
                            <InputError
                                key={key}
                                message={key + ': ' + message}
                            />
                        ))}
                    {form.isDirty && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {t('research.dirty')}
                        </p>
                    )}
                    {!frozen && (
                        <div>
                            <Button disabled={form.processing}>
                                {form.processing && <Spinner />}
                                {t('research.save')}
                            </Button>
                        </div>
                    )}
                </form>
                {!frozen && (
                    <Dialog open={verifyOpen} onOpenChange={setVerifyOpen}>
                        <DialogTrigger asChild>
                            <Button
                                variant="outline"
                                disabled={
                                    form.isDirty ||
                                    form.processing ||
                                    question.verification.verified
                                }
                            >
                                {t('research.verify')}
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>
                                    {t('research.verify')}
                                </DialogTitle>
                                <DialogDescription>
                                    {t('research.attestation')}
                                </DialogDescription>
                            </DialogHeader>
                            <Form
                                action={endpoint + '/verify'}
                                method="post"
                                options={{ preserveScroll: true }}
                                onSuccess={() => setVerifyOpen(false)}
                            >
                                {({
                                    processing,
                                    errors: verificationErrors,
                                }) => (
                                    <div className="space-y-4">
                                        <input
                                            type="hidden"
                                            name="lock_version"
                                            value={version}
                                        />
                                        <label className="flex items-start gap-3 text-sm">
                                            <input
                                                type="checkbox"
                                                name="confirmed"
                                                required
                                                className="mt-1 accent-primary"
                                            />
                                            {t('research.attestation')}
                                        </label>
                                        {Object.entries(verificationErrors).map(
                                            ([field, message]) => (
                                                <InputError
                                                    key={field}
                                                    message={
                                                        field + ': ' + message
                                                    }
                                                />
                                            ),
                                        )}
                                        <DialogFooter>
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                >
                                                    {t('research.cancel')}
                                                </Button>
                                            </DialogClose>
                                            <Button disabled={processing}>
                                                {processing && <Spinner />}
                                                {t('research.verify')}
                                            </Button>
                                        </DialogFooter>
                                    </div>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                )}
            </CardContent>
        </Card>
    );
}
