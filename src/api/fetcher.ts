interface PostRequestProps {
  url: string;
  data: {
    mode: string
    prompt?: {
      role: string
      content: string
    }[]
    [key: string]: any
  }
  exec: string
  formToken: string
  signal?: AbortSignal
}

/**
 * ACMS のブログ解決は URL コンテキストで行われるため、
 * 現在ブログが子ブログのときは POST 先 URL に `bid/<id>/` を含める。
 */
const resolveBlogUrl = (baseUrl: string): string => {
  const bid = window.ACMS?.Config?.bid
  if (bid === undefined || bid === null || bid === '') return baseUrl
  if (/\/bid\/[^/]+\/?$/.test(baseUrl)) return baseUrl
  const normalized = baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`
  return `${normalized}bid/${bid}/`
}

export const postRequest = async (props: PostRequestProps) => {
  const { url, data, exec, formToken, signal } = props

  const formData =  new FormData();
  if (data.prompt !== undefined) {
    formData.append('prompt', JSON.stringify(data.prompt))
  }
  formData.append('mode', data.mode)
  Object.keys(data).forEach(key => {
    if (key !== 'prompt' && key !== 'mode') {
      formData.append(key, data[key]);
    }
  });
  formData.append(exec, 'exec')
  formData.append('formToken', formToken)

  const response = await fetch(resolveBlogUrl(url), {
    method: 'POST',
    body: formData,
    signal,
  });
  if(!response.ok) {
    return null;
  }

  return response.json();
};

interface PostStreamingRequestProps {
  url: string;
  data: {
    input: string;
    previousResponseId?: string;
    [key: string]: unknown;
  };
  exec: string;
  formToken: string;
}

export type PostStreamingResult =
  | { ok: true; response: Response }
  | { ok: false; status: number; errorBody: string };

export const postStreamingRequest = async (
  props: PostStreamingRequestProps
): Promise<PostStreamingResult> => {
  const { url, data, exec, formToken } = props;

  const formData = new FormData();
  formData.append('input', data.input);
  if (data.previousResponseId) {
    formData.append('previousResponseId', data.previousResponseId);
  }
  Object.keys(data).forEach((key) => {
    if (key !== 'input' && key !== 'previousResponseId') {
      const value = data[key];
      formData.append(key, String(value));
    }
  });
  formData.append(exec, 'exec');
  formData.append('formToken', formToken);

  const response = await fetch(resolveBlogUrl(url), {
    method: 'POST',
    body: formData,
  });

  if (!response.ok || !response.body) {
    const errorBody = await response.text().catch(() => '')
    return { ok: false, status: response.status, errorBody }
  }

  return { ok: true, response }
};
