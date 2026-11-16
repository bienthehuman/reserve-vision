<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProofFileType;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Models\User;
use App\Rules\PaymentProofFile;
use App\Support\Pesos;
use App\Support\Proofs\ProofFileInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * `POST /reservations/{reservation}/proofs`: the file, the amount paid (pesos) and the reference
 * number. The reservation is looked up among the renter's own BEFORE validating, so another
 * renter's id is a 404 whatever the body (T6), never a validation message. Whether the hold
 * still takes a proof is UploadPaymentProof's rule, on the locked row.
 */
final class StorePaymentProofRequest extends FormRequest
{
    private ?Reservation $reservation = null;

    public function authorize(): bool
    {
        return Gate::authorize('upload', [PaymentProof::class, $this->reservation()])->allowed();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'proof' => ['bail', 'required', new PaymentProofFile],
            'amount' => ['bail', 'required', 'string', 'max:16', 'regex:'.Pesos::PATTERN, $this->positiveAmount()],
            'reference_no' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proof.required' => __('Choose the screenshot, photo or PDF of your receipt.'),
            // PHP refused the upload before Laravel saw it (upload_max_filesize).
            'proof.uploaded' => __('This file is larger than 5 MB. Take a screenshot of the receipt instead, or choose a smaller file.'),
            'amount.required' => __('Enter the amount you paid.'),
            'amount.regex' => __('Enter the amount in pesos, for example 1500 or 1500.50.'),
            'amount.max' => __('Enter the amount in pesos, for example 1500 or 1500.50.'),
            'reference_no.required' => __('Enter the reference number from your receipt.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reference_no' => __('reference number')];
    }

    /** The renter's own reservation from the route, else 404 (also for a landlord, whom the `renter` middleware stops first). */
    public function reservation(): Reservation
    {
        if ($this->reservation === null) {
            /** @var User $renter (auth + renter middleware) */
            $renter = $this->user();
            $id = $this->route('reservation');

            $this->reservation = Reservation::query()->ownedByRenter($renter)->findOrFail(is_numeric($id) ? (int) $id : 0);
        }

        return $this->reservation;
    }

    public function proofFile(): UploadedFile
    {
        $file = $this->file('proof');

        return $file instanceof UploadedFile ? $file : throw new LogicException('Read the proof only after validation.');
    }

    /** What the file is, by its content: already checked by PaymentProofFile. */
    public function fileType(): ProofFileType
    {
        return app(ProofFileInspector::class)->detect($this->proofFile()->getPathname())
            ?? throw new LogicException('Read the proof only after validation.');
    }

    public function amount(): int
    {
        return Pesos::toCentavos($this->string('amount')->toString());
    }

    public function referenceNo(): string
    {
        return $this->string('reference_no')->trim()->toString();
    }

    private function positiveAmount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! Pesos::matches($value)) {
                return; // the regex rule has said so
            }

            $centavos = Pesos::toCentavos($value);

            if ($centavos < 1 || $centavos > Pesos::MAX_CENTAVOS) {
                $fail(__('Enter the amount you paid, more than ₱0.'));
            }
        };
    }
}
