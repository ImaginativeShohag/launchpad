import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  deleteProduct,
  deleteScreenshot,
  removeLogo,
  reorderScreenshots,
  scheduleLaunch,
  submitProduct,
  updateProduct,
  uploadLogo,
  uploadScreenshot,
  withdrawProduct,
  type ProductPatch,
} from '../endpoints/founder'
import { patchMyProduct, removeFounderProduct, writeFounderProduct } from '../founderCache'

/* Not optimistic: submit, schedule, withdraw, delete and every upload wait for the server (spec 4.4). They write the response. */

export function useSubmitProduct() {
  const qc = useQueryClient()
  return useMutation({ mutationFn: (id: string) => submitProduct(id), onSuccess: (dto) => writeFounderProduct(qc, dto) })
}

export function useWithdrawProduct() {
  const qc = useQueryClient()
  return useMutation({ mutationFn: (id: string) => withdrawProduct(id), onSuccess: (dto) => writeFounderProduct(qc, dto) })
}

export function useScheduleLaunch() {
  const qc = useQueryClient()
  return useMutation({ mutationFn: ({ id, date }: { id: string; date: string }) => scheduleLaunch(id, date), onSuccess: (dto) => writeFounderProduct(qc, dto) })
}

export function useDeleteDraft() {
  const qc = useQueryClient()
  return useMutation({ mutationFn: (id: string) => deleteProduct(id), onSuccess: (_r, id) => removeFounderProduct(qc, id) })
}

/** Post-launch edit: the API accepts only the live fields (description, website, links) and audits the change. */
export function useEditLiveProduct() {
  const qc = useQueryClient()
  return useMutation({ mutationFn: ({ id, patch }: { id: string; patch: ProductPatch }) => updateProduct(id, patch), onSuccess: (dto) => writeFounderProduct(qc, dto) })
}

/* Media: the product id is a variable, because the first upload may come right after the draft was created. */

export function useUploadLogo() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, file, onProgress }: { id: string; file: File; onProgress?: (pct: number) => void }) => uploadLogo(id, file, onProgress),
    onSuccess: (dto, { id }) => {
      patchMyProduct(qc, id, (p) => ({ ...p, logo_url: dto.logo_url }))
      void qc.invalidateQueries({ queryKey: ['my-products'] })
    },
  })
}

export function useRemoveLogo() {
  const qc = useQueryClient()
  return useMutation({ mutationFn: (id: string) => removeLogo(id), onSuccess: (_r, id) => {
      patchMyProduct(qc, id, (p) => ({ ...p, logo_url: null }))
      void qc.invalidateQueries({ queryKey: ['my-products'] })
    },
  })
}

export function useUploadScreenshot() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, file, onProgress }: { id: string; file: File; onProgress?: (pct: number) => void }) => uploadScreenshot(id, file, onProgress),
    onSuccess: (shot, { id }) => {
      patchMyProduct(qc, id, (p) => ({ ...p, screenshots: [...(p.screenshots ?? []).filter((s) => s.id !== shot.id), shot] }))
      void qc.invalidateQueries({ queryKey: ['my-products'] })
    },
  })
}

export function useReorderScreenshots() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ids }: { id: string; ids: string[] }) => reorderScreenshots(id, ids),
    onSuccess: (shots, { id }) => {
      patchMyProduct(qc, id, (p) => ({ ...p, screenshots: shots }))
      void qc.invalidateQueries({ queryKey: ['my-products'] })
    },
  })
}

export function useDeleteScreenshot() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, shotId }: { id: string; shotId: string }) => deleteScreenshot(id, shotId),
    onSuccess: (_r, { id, shotId }) => {
      patchMyProduct(qc, id, (p) => ({ ...p, screenshots: (p.screenshots ?? []).filter((s) => s.id !== shotId) }))
      void qc.invalidateQueries({ queryKey: ['my-products'] })
    },
  })
}
