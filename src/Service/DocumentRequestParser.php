<?php
/**
 * Created by PhpStorm.
 * User: Giansalex
 * Date: 13/02/2018
 * Time: 22:17
 */

namespace App\Service;

use Greenter\Model\Despatch\Despatch;
use Greenter\Model\DocumentInterface;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\Request;

class DocumentRequestParser implements RequestParserInterface
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * DocumentRequestParser constructor.
     * @param SerializerInterface $serializer
     */
    public function __construct(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * @param Request $request
     * @param string $class
     * @return mixed
     */
    function getObject(Request $request, string $class): DocumentInterface
    {
        $data = $request->getContent();

        $dataJson = json_decode($data, true);
        if (!is_array($dataJson)) {
            throw new \InvalidArgumentException('JSON inválido en el body');
        }
        if ($class === Despatch::class) {
            $dataJson = DespatchDateNormalizer::normalize($dataJson);
        }
        if(array_key_exists('document', $dataJson)) {
            $doc = $dataJson['document'];
            if ($class === Despatch::class && is_array($doc)) {
                $doc = DespatchDateNormalizer::normalize($doc);
            }
            return $this->serializer->deserialize(
                json_encode($doc),
                $class,
                'json'
            );
        }

        return $this->serializer->deserialize(
            json_encode($dataJson),
            $class,
            'json'
        );
    }

    /**
     * @param Request $request
     * @param string $key
     * @return mixed
     */
    function getKey(Request $request, string $key): ?Array
    {
        $data = json_decode($request->getContent(), true);

        return array_key_exists($key, $data) ? $data[$key] : null;
    }
}