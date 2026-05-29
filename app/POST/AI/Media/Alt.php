<?php

/**
 * Todo: CMSv3.3で対応予定のため、一旦コメントアウト
 */

// namespace Acms\Plugins\AI\POST\AI\media;

// use Common;
// use Acms\Services\Facades\Media as MediaService;
// use Acms\Services\Facades\PublicStorage;
// use Acms\Plugins\AI\POST\AI\Media;
// use Acms\Plugins\AI\Services\AI\Endpoints\ResponsesClient;

// class Alt extends Media
// {
//     public function post()
//     {
//         $this->initAiConfig();

//         if (!$this->apiKey || !$this->model) {
//             \AcmsLogger::notice('APIキーまたはモデルの設定がありません。');
//             return Common::responseJson(['altText' => null]);
//         }

//         $mid = $this->Post->get("mid");

//         $media = MediaService::getMedia($mid);
//         $dataUrl = $this->convertImageToBase64DataUrl($media['path']);

//         if (empty($dataUrl)) {
//             \AcmsLogger::error("dataUrl is empty, aborting alt text generation for mid: " . $mid);
//             return Common::responseJson(['altText' => null]);
//         }

//         $responsesClient = new ResponsesClient($this->apiKey, $this->model);
//         $responsesClient->createPayload();
//         $text = $responsesClient->createTextContent(
//             "Please describe the content of this image in a single concise sentence suitable for use as "
//             . "Japanese alt text. "
//             . "End the sentence with a noun (体言止め) or a conclusive verb form (言い切り), without a trailing period. "
//             . "Respond in Japanese."
//         );
//         $image = $responsesClient->createImageContent($dataUrl);
//         $responsesClient->addInput("user", [$text, $image]);
//         $result = $responsesClient->request();

//         if ($result === null) {
//             \AcmsLogger::error("ResponsesClient returned null for mid: " . $mid);
//             return Common::responseJson(['altText' => null]);
//         }

//         if (isset($result->error)) {
//             \AcmsLogger::error("OpenAI API error for mid: " . $mid . " / " . json_encode($result->error));
//             return Common::responseJson(['altText' => null]);
//         }

//         $altText = ResponsesClient::extractText($result);

//         if ($altText === null) {
//             \AcmsLogger::error("extractText returned null, response: " . json_encode($result));
//         }

//         return Common::responseJson(['altText' => $altText]);
//     }

//     /**
//      * メディアパスをBase64エンコードしたdata URLに変換する
//      *
//      * @param string $mediaPath $media['path'] の値
//      * @return string Base64エンコードされたdata URL、失敗時は空文字
//      */
//     private function convertImageToBase64DataUrl(string $mediaPath): string
//     {
//         $extension = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));

//         switch ($extension) {
//             case 'jpg':
//             case 'jpeg':
//                 $mimeType = 'image/jpeg';
//                 break;
//             case 'png':
//                 $mimeType = 'image/png';
//                 break;
//             case 'gif':
//                 $mimeType = 'image/gif';
//                 break;
//             default:
//                 \AcmsLogger::error("Unsupported image format: " . $extension);
//                 return '';
//         }

//         $storagePath = MEDIA_LIBRARY_DIR . $mediaPath;

//         try {
//             $imageData = PublicStorage::get($storagePath);
//         } catch (\Exception $e) {
//             \AcmsLogger::error("Failed to read media file: " . $storagePath . " / " . $e->getMessage());
//             return '';
//         }

//         if ($imageData === false || $imageData === null) {
//             \AcmsLogger::error("Failed to read media file: " . $storagePath);
//             return '';
//         }

//         return "data:{$mimeType};base64," . base64_encode($imageData);
//     }
// }
