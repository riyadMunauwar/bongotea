import { useEffect, useRef } from 'react';
import { bn } from '@/lang/bn';

export type Video = { id: string; title: string };

/**
 * A YouTube video in a modal over the page, so the buyer never leaves the
 * store. A native `<dialog>`: Escape closes it, focus stays inside it, and a
 * press outside the video closes it as the mockup's does. The player is only
 * mounted while open, so closing stops the sound.
 */
export function VideoDialog({ video, onClose }: { video: Video | null; onClose: () => void }) {
    const dialog = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const element = dialog.current;

        if (!element) {
            return;
        }

        if (video && !element.open) {
            element.showModal();
        }

        if (!video && element.open) {
            element.close();
        }
    }, [video]);

    return (
        <dialog
            ref={dialog}
            aria-label={video?.title}
            onClose={onClose}
            onClick={(event) => event.target === event.currentTarget && onClose()}
            className="m-0 h-full max-h-none w-full max-w-none items-center justify-center bg-transparent p-4 backdrop:bg-scrim open:flex"
        >
            {video && (
                <div className="flex w-full max-w-[900px] flex-col gap-3">
                    <div className="flex items-center justify-between gap-3 text-white">
                        <b className="text-[clamp(16px,1.6vw,19px)] font-semibold">{video.title}</b>
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label={bn.blocks.closeVideo}
                            className="flex size-11 cursor-pointer items-center justify-center rounded-full border-0 bg-white/12 text-[22px] text-white hover:bg-white/22"
                        >
                            ✕
                        </button>
                    </div>
                    <div className="relative aspect-video w-full overflow-hidden rounded-[14px] bg-black">
                        <iframe
                            src={`https://www.youtube.com/embed/${video.id}?autoplay=1&rel=0`}
                            title={video.title}
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            allowFullScreen
                            className="absolute inset-0 size-full border-0"
                        />
                    </div>
                    <span className="text-center text-[13px] text-night-muted">{bn.blocks.videoDismissHint}</span>
                </div>
            )}
        </dialog>
    );
}
